<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\ShuffleSessionStatus;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\Purchase;
use App\Models\RewardCampaign;
use App\Models\ShuffleSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The token is the whole of the public security model. It is minted here and nowhere
 * else, and it is the only thing the QR code carries - no customer id, no purchase id,
 * nothing sequential - so a photographed URL reveals one reward and not a database.
 */
class ShuffleSessionService
{
    private const LIFETIME_HOURS = 24;

    public function __construct(private readonly RewardEligibilityService $eligibility) {}

    /**
     * Null when the purchase does not qualify; the caller asks for the reason.
     *
     * The unique index on `purchase_id` is caught rather than pre-empted: two staff
     * pressing the button in the same second both pass the eligibility check and both
     * insert, and the loser reads back the row the winner wrote.
     */
    public function mintFor(Purchase $purchase, ?User $staff = null): ?ShuffleSession
    {
        $campaign = $this->eligibility->campaignFor($purchase);

        if ($campaign === null || ! $this->eligibility->qualifies($purchase, $campaign)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($campaign, $purchase, $staff): ShuffleSession {
                $session = new ShuffleSession([
                    'campaign_id' => $campaign->id,
                    'customer_id' => $purchase->customer_id,
                    'visit_id' => $purchase->visit_id,
                    'purchase_id' => $purchase->id,
                    'expires_at' => CarbonImmutable::now()->addHours(self::LIFETIME_HOURS),
                    'status' => ShuffleSessionStatus::Pending,
                ]);

                # Neither is fillable, and neither may become fillable: a token that
                # could arrive on a request is a token somebody could choose.
                $session->forceFill([
                    'token' => $this->token(),
                    'created_by' => $staff?->id,
                ])->save();

                return $session;
            });
        } catch (QueryException $exception) {
            # Lost the race - the turn exists, which is the wanted outcome. Anything
            # else is a real fault and is re-thrown.
            $existing = $purchase->shuffleSession()->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * Another go, given by hand rather than earned: a turn with no purchase behind it.
     *
     * It steps over `max_shuffles_per_customer` on purpose. That cap governs what a
     * campaign hands out on its own; this is somebody deciding otherwise, and
     * `created_by` records who. What it does not step over is the campaign being open
     * and there being something left to win.
     *
     * The new turn names no products, so it draws only from the unpaired rewards -
     * `RewardEligibilityService::productIdsOn(null)`. A tray paired to an oven stays
     * for whoever bought the oven, whatever goodwill is owed elsewhere.
     */
    public function grantAfter(ShuffleSession $previous, ?User $staff = null): ShuffleSession
    {
        $campaign = $previous->campaign;
        $now = CarbonImmutable::now();

        if (! $campaign->isRunning($now)) {
            throw ShuffleUnavailableException::campaignClosed();
        }

        if ($this->eligibility->availableCountFor($campaign, []) === 0) {
            throw ShuffleUnavailableException::poolEmpty();
        }

        if ($this->holdsALiveTurn($campaign, $previous->customer_id, $now)) {
            throw ShuffleUnavailableException::turnOutstanding();
        }

        $session = new ShuffleSession([
            'campaign_id' => $campaign->id,
            'customer_id' => $previous->customer_id,
            # The visit carries across, so the extra turn still reports under the walk-in
            # it came out of. The purchase does not: one turn per sale, and that sale has
            # had its - the unique index on `purchase_id` would refuse it anyway.
            'visit_id' => $previous->visit_id,
            'purchase_id' => null,
            'expires_at' => $now->addHours(self::LIFETIME_HOURS),
            'status' => ShuffleSessionStatus::Pending,
        ]);

        $session->forceFill([
            'token' => $this->token(),
            'created_by' => $staff?->id,
        ])->save();

        return $session;
    }

    /**
     * A turn the customer could still play right now. A pending row whose window has
     * closed is not one - it is waiting for `rewards:expire` to say so.
     */
    private function holdsALiveTurn(RewardCampaign $campaign, int $customerId, CarbonImmutable $at): bool
    {
        return $campaign->sessions()
            ->where('customer_id', $customerId)
            ->where('status', ShuffleSessionStatus::Pending)
            ->where(fn (Builder $window) => $window
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $at))
            ->exists();
    }

    public function forToken(string $token, ?CarbonImmutable $at = null): ShuffleSession
    {
        $at ??= CarbonImmutable::now();

        $session = ShuffleSession::query()
            ->with(['campaign', 'customer'])
            ->where('token', $token)
            ->first();

        if ($session === null) {
            throw ShuffleUnavailableException::unknown();
        }

        $this->assertShuffleable($session, $at);

        return $session;
    }

    /**
     * Read twice on purpose: here so the screen can be drawn, and again under the lock
     * in `ShuffleRewardService`, where it is authoritative. This copy decides what
     * somebody sees; that one decides what they get.
     */
    public function assertShuffleable(ShuffleSession $session, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        match ($session->status) {
            ShuffleSessionStatus::Shuffled => throw ShuffleUnavailableException::alreadyUsed(),
            ShuffleSessionStatus::Expired => throw ShuffleUnavailableException::expired(),
            ShuffleSessionStatus::Cancelled => throw ShuffleUnavailableException::cancelled(),
            ShuffleSessionStatus::Pending => null,
        };

        if ($session->hasExpired($at)) {
            throw ShuffleUnavailableException::expired();
        }

        if (! $session->campaign->isRunning($at)) {
            throw ShuffleUnavailableException::campaignClosed();
        }
    }

    /**
     * A turn that has already been shuffled cannot be cancelled - that would orphan the
     * result behind it. Cancel the result instead.
     */
    public function cancel(ShuffleSession $session): void
    {
        if ($session->status !== ShuffleSessionStatus::Pending) {
            throw ShuffleUnavailableException::alreadyUsed();
        }

        $session->forceFill(['status' => ShuffleSessionStatus::Cancelled])->save();
    }

    /**
     * `Str::random` is `random_bytes` underneath - a seeded generator would be a token
     * somebody could enumerate offline.
     */
    private function token(): string
    {
        return Str::random(64);
    }
}
