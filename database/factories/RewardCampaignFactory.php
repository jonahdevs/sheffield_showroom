<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\RewardCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardCampaign>
 */
class RewardCampaignFactory extends Factory
{
    protected $model = RewardCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->monthName().' showroom rewards',
            'description' => fake()->optional()->sentence(),
            /* Draft by default: a campaign has no pool until somebody
               publishes it, and a fixture that arrives already running would
               let a test shuffle against an empty drawer. */
            'status' => CampaignStatus::Draft,
            'starts_at' => null,
            'ends_at' => null,
            'max_shuffles_per_customer' => 1,
            'minimum_purchase_amount' => null,
            'created_by' => User::factory(),
        ];
    }

    /** Running now, with the calendar deliberately open at both ends. */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Active,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addWeek(),
        ]);
    }

    /** Over, so nothing new can be shuffled against it. */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Active,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Paused,
        ]);
    }

    public function requiring(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'minimum_purchase_amount' => $amount,
        ]);
    }
}
