<?php

namespace Database\Factories;

use App\Enums\ShuffleSessionStatus;
use App\Models\Customer;
use App\Models\RewardCampaign;
use App\Models\ShuffleSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShuffleSession>
 */
class ShuffleSessionFactory extends Factory
{
    protected $model = ShuffleSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => RewardCampaign::factory()->active(),
            'customer_id' => Customer::factory(),
            'visit_id' => null,
            # The column is uniquely indexed, so two fixtures sharing a
            # purchase would collide. A test that cares about it says so.
            'purchase_id' => null,
            'token' => Str::random(64),
            'expires_at' => now()->addDay(),
            'status' => ShuffleSessionStatus::Pending,
            'created_by' => User::factory(),
        ];
    }

    /** A turn whose window has closed. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    /** A turn already taken, which is what a refreshed page finds. */
    public function shuffled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShuffleSessionStatus::Shuffled,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShuffleSessionStatus::Cancelled,
        ]);
    }

    /** No deadline at all. */
    public function neverExpiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => null,
        ]);
    }
}
