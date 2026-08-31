<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\PoolEntryStatus;
use App\Enums\RewardResultStatus;
use App\Enums\ShuffleSessionStatus;
use App\Exceptions\ShuffleUnavailableException;
use App\Models\RewardPoolEntry;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Claiming one reward, once.
 *
 * This is the only place in the application that decides what somebody wins,
 * and the only one that may. The customer's browser is told the answer after
 * the fact and animates towards it; nothing it sends can influence it, because
 * nothing it sends is read here beyond the token that got it this far.
 *
 * The whole method is one transaction, and the order inside it matters:
 *
 *  1. Lock the session row. Whoever holds this lock owns the turn, so a
 *     refreshed page and a second phone queue behind each other rather than
 *     both proceeding.
 *  2. Re-check the state under that lock. The screen checked it too, but that
 *     was a moment ago and a moment is enough.
 *  3. Work out what this purchase is even in the running for. Rewards may be
 *     paired to a product - buy the oven, win the tray - and that is resolved
 *     here, before anything is locked, so the statement below stays narrow.
 *  4. Pick and lock an available pool entry in one statement - see `claim()`.
 *  5. Mark it claimed, write the result, mark the session shuffled.
 *
 * The architecture document describes this as "lock an available entry, then
 * randomly choose one". That order is wrong: locking first and choosing second
 * lets two transactions pick the same row out of the set they both locked.
 * Selection and locking have to be the same statement.
 *
 * Under all of it sit two unique indexes - one result per session, one result
 * per pool entry. If the locking above is ever broken by a later change, the
 * second writer gets an integrity error and nobody wins the same reward twice.
 * That is the correct failure, and there is a test that provokes it.
 */
class ShuffleRewardService
{
    public function __construct(
        private readonly ShuffleSessionService $sessions,
        private readonly RewardEligibilityService $eligibility,
    ) {}

    /**
     * Runs the shuffle and returns what was won.
     *
     * The same method serves the customer's phone and the staff screen. There
     * is deliberately no second implementation for the fallback: two ways of
     * choosing a reward is two ways of choosing it wrongly.
     */
    public function claim(ShuffleSession $session, ?CarbonImmutable $at = null): ShuffleResult
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($session, $at): ShuffleResult {
            /* The turn itself, locked. Everything below happens while this row
               is held, which is what makes the whole operation one-at-a-time
               per session. */
            $session = ShuffleSession::query()
                ->with(['campaign', 'purchase'])
                ->lockForUpdate()
                ->find($session->id);

            if ($session === null) {
                throw ShuffleUnavailableException::unknown();
            }

            /* Authoritative. The copy of this check that drew the screen was
               true when it ran and may not be now. */
            $this->sessions->assertShuffleable($session, $at);

            /* Resolved before the lock is taken, never during it. This is the
               one thing standing between a paired reward and the wrong
               customer, and it costs a single query - see
               `RewardEligibilityService::qualifyingRewardIds()`. */
            $rewardIds = $this->eligibility->qualifyingRewardIds(
                $session->campaign,
                $session->purchase?->product_id,
            );

            $entry = $rewardIds === []
                ? null
                : $this->lockAvailableEntry($session->campaign_id, $rewardIds);

            if ($entry === null) {
                /* Nothing is spent. The turn stays pending because the
                   customer did nothing wrong, and a showroom that adds stock
                   back should find them still holding it. */
                throw ShuffleUnavailableException::poolEmpty();
            }

            $entry->forceFill([
                'status' => PoolEntryStatus::Claimed,
                'claimed_at' => $at,
            ])->save();

            $result = ShuffleResult::query()->create([
                'shuffle_session_id' => $session->id,
                'reward_pool_entry_id' => $entry->id,
                'code' => $this->code(),
                'won_at' => $at,
                /* Stamped from the definition now, never recomputed later, so
                   an administrator editing `validity_days` afterwards cannot
                   move a deadline somebody already has. */
                'expires_at' => $entry->reward->expiryFrom($at),
                'status' => RewardResultStatus::Unredeemed,
            ]);

            $session->forceFill(['status' => ShuffleSessionStatus::Shuffled])->save();

            return $result->setRelation('poolEntry', $entry);
        });
    }

    /**
     * One available unit of this campaign that this customer may win, locked
     * for the caller.
     *
     * Selection and locking in a single statement, which is the point. The
     * random order is what makes it a shuffle; `lockForUpdate` is what makes
     * it safe. A second transaction running this at the same moment blocks
     * here rather than picking the same row, and when it is let through the
     * row it was about to take is no longer `available`, so it takes another.
     *
     * Ordering by a random expression rather than shuffling in PHP, because
     * PHP would have to read the whole pool to shuffle it and would then be
     * choosing from rows it never locked.
     *
     * `$rewardIds` narrows the draw to what the purchase qualifies for, as a
     * literal `IN` rather than a join to `campaign_reward_product`. The ids
     * were resolved above, before the transaction took any lock, precisely so
     * this statement still reads one table through one index.
     *
     * @param  array<int, int>  $rewardIds
     */
    private function lockAvailableEntry(int $campaignId, array $rewardIds): ?RewardPoolEntry
    {
        return RewardPoolEntry::query()
            ->with('reward.reward')
            ->where('campaign_id', $campaignId)
            ->where('status', PoolEntryStatus::Available)
            ->whereIn('campaign_reward_id', $rewardIds)
            ->inRandomOrder()
            ->lockForUpdate()
            ->first();
    }

    /**
     * What the customer quotes when they come back for the reward weeks later.
     *
     * Read aloud across a counter and typed in by somebody else, so the
     * alphabet leaves out the characters that get misread: no O or 0, no I, 1
     * or L. Collisions are refused by the unique index rather than guarded
     * against here - at showroom volumes a repeat is vanishingly unlikely, and
     * the retry below costs nothing on the occasion it happens.
     */
    private function code(): string
    {
        do {
            $code = 'SHF-'.$this->readableSuffix();
        } while (ShuffleResult::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Six characters nobody will misread over a counter: no O or 0, no I, 1
     * or L. `random_int` rather than `rand`, because a reward code somebody
     * can predict is a reward code somebody can claim.
     */
    private function readableSuffix(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $suffix = '';

        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}
