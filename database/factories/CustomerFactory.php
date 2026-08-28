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
            'date_of_birth' => fake()->optional()->dateTimeBetween('-70 years', '-18 years'),
            'occupation' => fake()->optional()->jobTitle(),
            'phone' => $this->kenyanPhone(),
            'alternative_phone' => fake()->optional()->passthrough($this->kenyanPhone()),
            'email' => fake()->optional()->safeEmail(),
            'address_line_1' => fake()->optional()->streetAddress(),
            'address_line_2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->optional()->city(),
            'state' => fake()->optional()->randomElement(['Nairobi', 'Kiambu', 'Nakuru', 'Mombasa', 'Kisumu']),
            'postal_code' => fake()->optional()->postcode(),
            'country' => 'Kenya',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Swaps the person's fields for the organisation's, so a company row never
     * carries a stray name or date of birth.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerType::Company,
            'name' => null,
            'date_of_birth' => null,
            'occupation' => null,
            'company_name' => fake()->company(),
            'industry' => fake()->randomElement(['Construction', 'Manufacturing', 'Real Estate', 'Agriculture']),
            'contact_person' => fake()->name(),
            'contact_person_position' => fake()->optional()->jobTitle(),
        ]);
    }

    private function kenyanPhone(): string
    {
        return '07'.fake()->numerify('## ### ###');
    }
}
