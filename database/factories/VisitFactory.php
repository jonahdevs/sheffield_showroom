<?php

namespace Database\Factories;

use App\Enums\CustomerSource;
use App\Enums\VisitPurpose;
use App\Models\Customer;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            /* In the past, because `VisitRequest` will not accept anything
               else and a fixture should not describe a row the form refuses. */
            'visited_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'purpose' => fake()->randomElement(VisitPurpose::cases()),
            'source' => fake()->randomElement(CustomerSource::cases()),
            'duration_minutes' => fake()->optional()->numberBetween(5, 180),
            'notes' => fake()->optional()->sentence(),
            'expected_follow_up_on' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'respondent' => fake()->name(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Logged by a particular salesperson, which is what the `view.own` split
     * turns on.
     */
    public function loggedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    public function for_purpose(VisitPurpose $purpose): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => $purpose,
        ]);
    }
}
