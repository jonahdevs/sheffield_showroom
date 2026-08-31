<?php

namespace Database\Factories;

use App\Models\RewardRedemption;
use App\Models\ShuffleResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardRedemption>
 */
class RewardRedemptionFactory extends Factory
{
    protected $model = RewardRedemption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shuffle_result_id' => ShuffleResult::factory()->redeemed(),
            'redeemed_by' => User::factory(),
            'redeemed_at' => now(),
            'notes' => null,
        ];
    }
}
