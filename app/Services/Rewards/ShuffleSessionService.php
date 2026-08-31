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
 * Minting and reading a customer's turn.
 *
 * The token is the whole of the public security model, so it is generated here
 * and nowhere else: 64 characters from `Str::random`, which is
 * `random_bytes` underneath rather than anything seeded. It is the only thing
 * the QR code carries - no customer id, no purchase id, nothing sequential -
 * so a URL somebody photographs over a shoulder reveals a reward and not a
 * database.
 */
class ShuffleSessionService
{
    /**
     * How long a QR code is worth scanning.
     *
     * Short because the customer is standing at the counter with the screen in
     * front of them. Long enough that a queue, a phone that needs unlocking,
     * and a walk to better signal do not cost somebody their reward.
     */
    private const LIFETIME_HOURS = 24;

    public function __construct(private readonly RewardEligibilityService $eligibility) {}

    /**
     * Gives a qualifying purchase its one turn.
     *
     * Returns null when the purchase does not qualify - the caller asks
     * `RewardEligibilityService` for the reason, because the reason is worth
     * showing and an exception here would be a refusal dressed as a fault.
     *
     * The unique index on `purchase_id` is caught rather than pre-empted. Two
     * members of staff pressing the button in the same second both pass the
     * eligibility check and both try to insert; the loser reads back the row
     * the winner wrote, so the customer gets one turn and neither screen shows
     * an error.
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

                /* `forceFill` because neither of these is fillable, and
                   neither should be: a token that could arrive on a request is
                   a token somebody could choose, and the staff member who
                   minted a turn is read from the session rather than from what
                   the form said. */
                $session->forceFill([
                    'token' => $this->token(),
                    'created_by' => $staff?->id,
                ])->save();

                return $session;
            });
        } catch (QueryException $exception) {
            /* Lost the race. The turn exists, which is the outcome that was
               wanted - anything else here is a real fault and is re-thrown. */
            $existing = $purchase->shuffleSession()->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * The turn behind a public token, ready to be shuffled.
     *
     * Every refusal is a `ShuffleUnavailableException` carrying a reason the
     * customer-facing screen can draw a state from. An unknown token and a
     * cancelled one are told apart internally but say much the same thing
     * outward: a customer does not need to learn which.
     */
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
     * The same checks the claiming transaction repeats under a lock.
     *
     * Read twice on purpose: once here so the screen can be drawn correctly,
     * and once inside `ShuffleRewardService` where it is authoritative. This
     * copy decides what somebody sees; that one decides what they get.
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
     * Takes a turn back before it is used - a sale reversed, or a QR code
     * printed for the wrong person.
     *
     * A turn that has already been shuffled cannot be cancelled: the reward
     * behind it was won, and cancelling the turn would leave the result
     * orphaned. Cancel the result instead.
     */
    public function cancel(ShuffleSession $session): void
    {
        if ($session->status !== ShuffleSessionStatus::Pending) {
            throw ShuffleUnavailableException::alreadyUsed();
        }

        $session->forceFill(['status' => ShuffleSessionStatus::Cancelled])->save();
    }

    /**
     * Opaque, and long enough that guessing is not a strategy.
     *
     * `Str::random` is `random_bytes` underneath - a token from a seeded
     * generator would be a token somebody could enumerate offline.
     */
    private function token(): string
    {
        return Str::random(64);
    }
}
