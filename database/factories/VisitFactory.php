<?php

namespace Database\Factories;

use App\Enums\CustomerSource;
use App\Enums\VisitDepartment;
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
            # In the past: `VisitRequest` will not accept anything else.
            'visited_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'purpose' => fake()->randomElement(VisitPurpose::values()),
            # Not a random case: `Referral` is the one source that must carry a
            # `referred_by`, and rolling it here would build rows the form
            # would refuse. Reach for `referredBy()` when you want one.
            'source' => CustomerSource::WalkIn->value,
            'department' => fake()->randomElement(VisitDepartment::values()),
            'notes' => fake()->optional()->sentence(),
            'expected_follow_up_on' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'respondent' => fake()->name(),
            'created_by' => User::factory(),
        ];
    }

    /** What the `view.own` split turns on. */
    public function loggedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    public function for_purpose(VisitPurpose $purpose): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => $purpose->value,
        ]);
    }

    public function for_department(VisitDepartment $department): static
    {
        return $this->state(fn (array $attributes) => [
            'department' => $department->value,
        ]);
    }

    /** The only source carrying a second fact - who made the referral. */
    public function referredBy(string $referrer): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => CustomerSource::Referral->value,
            'referred_by' => $referrer,
        ]);
    }
}
