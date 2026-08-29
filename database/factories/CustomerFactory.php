<?php

namespace Database\Factories;

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
     * The same person, buying for the business they work for.
     *
     * `name` is left as it is rather than swapped out: a company row names
     * whoever came in from it just as an individual row does, and the company
     * is what is added on top.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerType::Company,
            'company_name' => fake()->company(),
            'industry' => fake()->randomElement(['Construction', 'Manufacturing', 'Real Estate', 'Agriculture']),
        ]);
    }

    private function kenyanPhone(): string
    {
        return '07'.fake()->numerify('## ### ###');
    }
}
