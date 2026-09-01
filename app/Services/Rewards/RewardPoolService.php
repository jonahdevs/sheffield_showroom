<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\PoolEntryStatus;
use App\Models\CampaignReward;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A campaign says "twenty discounts"; this writes twenty rows. From then on the
 * promotion holds no probabilities at all - what can be won is what is in the table,
 * so the odds cannot drift and cannot hand out a twenty-first discount.
 */
class RewardPoolService
{
    private const CHUNK = 500;

    /**
     * One row per reward unit, the whole pool inside one transaction. A half-loaded
     * campaign is worse than one that failed to load: it hands out rewards against a
     * drawer nobody agreed to.
     *
     * @return int the number of units written
     */
    public function generate(RewardCampaign $campaign): int
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($campaign, $now): int {
            $written = 0;

            $rewards = $campaign->rewards()
                ->where('is_active', true)
                ->where('quantity', '>', 0)
                ->get();

            foreach ($rewards as $reward) {
                $written += $this->writeUnits($campaign, $reward, $now);
            }

            return $written;
        });
    }

    /**
     * @return array<int, array{available: int, claimed: int, void: int}> keyed by campaign_reward_id
     */
    public function inventory(RewardCampaign $campaign): array
    {
        $counted = $campaign->poolEntries()
            ->selectRaw('campaign_reward_id, status, count(*) as aggregate')
            ->groupBy('campaign_reward_id', 'status')
            ->get();

        $inventory = [];

        foreach ($counted as $row) {
            $rewardId = (int) $row->campaign_reward_id;

            $inventory[$rewardId] ??= ['available' => 0, 'claimed' => 0, 'void' => 0];
            $inventory[$rewardId][$row->status->value] = (int) $row->aggregate;
        }

        return $inventory;
    }

    /**
     * Only `available` rows are touched. `claimed` is one-way, and voiding rather than
     * deleting is what keeps loaded = available + claimed + void reconciling.
     *
     * @return int the number of units taken off the table
     */
    public function void(CampaignReward $reward, ?int $limit = null): int
    {
        return DB::transaction(function () use ($reward, $limit): int {
            $query = $reward->poolEntries()
                ->where('status', PoolEntryStatus::Available)
                # Oldest first, so voiding is repeatable and two people voiding ten
                # units each do not fight over the same rows.
                ->orderBy('id');

            if ($limit !== null) {
                $query->limit($limit);
            }

            return $query->update(['status' => PoolEntryStatus::Void]);
        });
    }

    private function writeUnits(
        RewardCampaign $campaign,
        CampaignReward $reward,
        CarbonImmutable $now,
    ): int {
        $remaining = $reward->quantity;

        while ($remaining > 0) {
            $size = min($remaining, self::CHUNK);

            $campaign->poolEntries()->insert(array_fill(0, $size, [
                'campaign_id' => $campaign->id,
                'campaign_reward_id' => $reward->id,
                'status' => PoolEntryStatus::Available->value,
                'claimed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $remaining -= $size;
        }

        return $reward->quantity;
    }
}
