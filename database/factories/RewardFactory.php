<?php

namespace Database\Factories;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\Product;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Free kitchen audit',
            'description' => null,
            'type' => RewardType::KitchenAudit,
            'product_id' => null,
            'value' => null,
            'value_unit' => null,
            'terms' => null,
            'default_validity_days' => 30,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    /** A reward carrying a figure, which most of them do not. */
    public function discount(float $percentage = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => rtrim(rtrim(number_format($percentage, 2), '0'), '.').'% discount',
            'type' => RewardType::Discount,
            'value' => $percentage,
            'value_unit' => RewardValueUnit::Percentage,
        ]);
    }

    public function ofType(RewardType $type, string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'name' => $name,
        ]);
    }

    /**
     * A thing off the floor rather than a discount or a service - the tray
     * somebody wins with the oven.
     */
    public function product(?Product $product = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => RewardType::Product,
            'product_id' => $product?->id ?? Product::factory(),
            'name' => $product?->name ?? 'Accessory',
            'value' => null,
            'value_unit' => null,
        ]);
    }

    /** Retired: still on campaigns that hold it, offered to no new one. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
