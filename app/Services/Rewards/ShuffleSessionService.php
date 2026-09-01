<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\ShuffleSessionStatus;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\Purchase;
use App\Models\ShuffleSession;
use App\Models\User;
use Carbon\CarbonImmutable;
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
