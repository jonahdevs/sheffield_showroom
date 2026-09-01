<?php

namespace Database\Factories;

use App\Enums\CustomerSegment;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            'phone' => $this->kenyanPhone(),
            'email' => fake()->optional()->safeEmail(),
            'id_number' => fake()->optional()->numerify('########'),
            'street_address' => fake()->optional()->streetAddress(),
            'area' => fake()->optional()->secondaryAddress(),
            'city' => fake()->optional()->city(),
            'state' => fake()->optional()->randomElement(['Nairobi', 'Kiambu', 'Nakuru', 'Mombasa', 'Kisumu']),
            'postal_code' => fake()->optional()->postcode(),
            'country' => 'Kenya',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * The same person, buying for the business they work for. `name` is left
     * as it is: a company row names whoever came in from it, and the company
     * is added on top.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerType::Company,
            'company_name' => fake()->company(),
            # The stored value, never the case: an enum instance here leaves the
            # in-memory model disagreeing with the row read back.
            'segment' => fake()->randomElement(array_values(array_diff(
                CustomerSegment::values(),
                [CustomerSegment::Other->value],
            ))),
        ]);
    }

    private function kenyanPhone(): string
    {
        return '07'.fake()->numerify('## ### ###');
    }
}
