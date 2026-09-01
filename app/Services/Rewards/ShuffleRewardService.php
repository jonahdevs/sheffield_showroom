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
 * The only place in the application that decides what somebody wins.
 */
class ShuffleRewardService
{
    public function __construct(
        private readonly ShuffleSessionService $sessions,
        private readonly RewardEligibilityService $eligibility,
    ) {}

    public function claim(ShuffleSession $session, ?CarbonImmutable $at = null): ShuffleResult
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($session, $at): ShuffleResult {
            # Locking the session row is what makes a turn one-at-a-time: a refreshed
            # page and a second phone queue behind each other rather than both proceeding.
            $session = ShuffleSession::query()
                ->with(['campaign', 'purchase.products'])
                ->lockForUpdate()
                ->find($session->id);

            if ($session === null) {
                throw ShuffleUnavailableException::unknown();
            }

            # Re-checked under the lock; the copy of this check that drew the screen
            # was true when it ran and may not be now.
            $this->sessions->assertShuffleable($session, $at);

            # Product pairing is resolved before the lock is taken, never joined into
            # the locking statement below.
            $rewardIds = $this->eligibility->qualifyingRewardIds(
                $session->campaign,
                $this->eligibility->productIdsOn($session->purchase),
            );

            $entry = $rewardIds === []
                ? null
                : $this->lockAvailableEntry($session->campaign_id, $rewardIds);

            if ($entry === null) {
                # Deliberately leaves the turn pending rather than failing it, so a
                # showroom that adds stock back finds the customer still holding it.
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
                # Stamped from the definition now, never recomputed, so editing
                # `validity_days` afterwards cannot move a deadline somebody already has.
                'expires_at' => $entry->reward->expiryFrom($at),
                'status' => RewardResultStatus::Unredeemed,
            ]);

            $session->forceFill(['status' => ShuffleSessionStatus::Shuffled])->save();

            return $result->setRelation('poolEntry', $entry);
        });
    }

    /**
     * One available unit of this campaign that this customer may win, locked for the caller.
     *
     * Selection and locking must stay in a single statement. Locking an available entry
     * first and randomly choosing second - the order the architecture document gives -
     * lets two concurrent transactions pick the same row out of the set they both locked.
     * Shuffling in PHP has the same fault: it would choose from rows it never locked.
     *
     * Two unique indexes sit under this - one result per session, one result per pool
     * entry - so a later change that breaks the locking fails with an integrity error
     * rather than handing the same reward out twice.
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

    private function code(): string
    {
        do {
            $code = 'SHF-'.$this->readableSuffix();
        } while (ShuffleResult::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Six characters nobody will misread over a counter: no O or 0, no I, 1 or L.
     * `random_int` rather than `rand` - a reward code somebody can predict is a
     * reward code somebody can claim.
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
