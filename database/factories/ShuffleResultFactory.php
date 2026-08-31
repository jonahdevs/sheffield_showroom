<?php

namespace Database\Factories;

use App\Enums\RewardResultStatus;
use App\Models\RewardPoolEntry;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShuffleResult>
 */
class ShuffleResultFactory extends Factory
{
    protected $model = ShuffleResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shuffle_session_id' => ShuffleSession::factory()->shuffled(),
            'reward_pool_entry_id' => RewardPoolEntry::factory()->claimed(),
            'code' => strtoupper(Str::random(10)),
            'won_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => RewardResultStatus::Unredeemed,
        ];
    }

    /** Won, and its window already closed. */
    public function lapsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function redeemed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RewardResultStatus::Redeemed,
        ]);
    }

    public function neverExpiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => null,
        ]);
    }
}
