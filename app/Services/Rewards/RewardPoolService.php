<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\PoolEntryStatus;
use App\Models\CampaignReward;
use App\Models\RewardCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turning reward definitions into countable inventory.
 *
 * A campaign says "twenty discounts". This writes twenty rows. From that
 * moment the promotion has no probabilities in it at all: what can still be
 * won is what is still in the table, so the odds cannot drift, cannot be
 * miscalculated, and cannot hand out a twenty-first discount.
 */
class RewardPoolService
{
    /**
     * How many rows to write at once.
     *
     * A campaign is a few hundred units, so this is not about scale - it is
     * about not building one enormous array in memory for the showroom that
     * one day runs a ten thousand unit promotion.
     */
    private const CHUNK = 500;

    /**
     * Writes one row per reward unit.
     *
     * Inserted rather than saved through the model: these rows carry nothing
     * but their two foreign keys and a status, there is no observer to run,
     * and a campaign is one statement rather than four hundred.
     *
     * The whole pool goes in inside one transaction. A campaign that is half
     * loaded is worse than one that failed to load - it would hand out rewards
     * against a drawer nobody agreed to.
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
     * What the campaign screen reads: how much of each reward is left, and how
     * much has gone.
     *
     * One grouped query rather than a count per reward, because a campaign
     * with five definitions in three states would otherwise be fifteen round
     * trips to draw one table.
     *
     * @return array<int, array{available: int, claimed: int, void: int}>
     *                                                                    keyed by campaign_reward_id
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
     * Takes unwon units off the table without pretending they were never
     * loaded.
     *
     * Only `available` rows are touched. A claimed unit is somebody's reward
     * and stays claimed however the campaign is later tidied - see
     * `PoolEntryStatus`, and the reporting identity it exists to keep:
     * loaded = available + claimed + void.
     *
     * @return int the number of units taken off the table
     */
    public function void(CampaignReward $reward, ?int $limit = null): int
    {
        return DB::transaction(function () use ($reward, $limit): int {
            $query = $reward->poolEntries()
                ->where('status', PoolEntryStatus::Available)
                /* Oldest first, so voiding is repeatable and two people
                   voiding ten units each do not fight over the same rows. */
                ->orderBy('id');

            if ($limit !== null) {
                $query->limit($limit);
            }

            return $query->update(['status' => PoolEntryStatus::Void]);
        });
    }

    /**
     * The rows for one definition, written in chunks.
     */
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
