<?php

namespace Database\Factories;

use App\Enums\PoolEntryStatus;
use App\Models\CampaignReward;
use App\Models\RewardPoolEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardPoolEntry>
 */
class RewardPoolEntryFactory extends Factory
{
    protected $model = RewardPoolEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reward = CampaignReward::factory();

        return [
            'campaign_reward_id' => $reward,
            /* The campaign is carried on the entry as well as on the reward
               above it - see the migration. Resolved from the reward so a
               fixture cannot describe a unit filed under one campaign and
               defined in another. */
            'campaign_id' => fn (array $attributes) => CampaignReward::query()
                ->whereKey($attributes['campaign_reward_id'])
                ->value('campaign_id'),
            'status' => PoolEntryStatus::Available,
            'claimed_at' => null,
        ];
    }

    public function claimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PoolEntryStatus::Claimed,
            'claimed_at' => now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PoolEntryStatus::Void,
        ]);
    }
}
