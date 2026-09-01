<?php

namespace Database\Factories;

use App\Enums\PurchaseStatus;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'visit_id' => null,
            'reference' => strtoupper(fake()->bothify('INV-####')),
            'amount' => fake()->randomFloat(2, 5_000, 500_000),
            'status' => PurchaseStatus::Completed,
            'purchased_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'created_by' => User::factory(),
        ];
    }

    /** A sale still being settled, which earns nothing. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseStatus::Pending,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PurchaseStatus::Cancelled,
        ]);
    }

    /** For a test standing either side of a threshold. */
    public function worth(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }
}
