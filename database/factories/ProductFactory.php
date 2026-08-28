<?php

namespace Database\Factories;

use App\Enums\ProductSource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->unique()->words(3, true)),
            'sku' => strtoupper(fake()->unique()->bothify('SS-####-??')),
            'image_path' => null,
            'source' => ProductSource::Manual,
        ];
    }

    /**
     * A row the website put here, which a sync owns and may replace.
     */
    public function fromWebsite(?int $externalId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => ProductSource::Website,
            'external_id' => $externalId ?? fake()->unique()->numberBetween(1, 100000),
            'synced_at' => now(),
        ]);
    }

    public function withoutSku(): static
    {
        return $this->state(fn (array $attributes) => ['sku' => null]);
    }
}
