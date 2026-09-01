<?php

namespace Database\Factories;

use App\Enums\RewardType;
use App\Models\CampaignReward;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The descriptive states - `discount()`, `ofType()`, `product()` - build the
 * catalogue row behind the attachment rather than setting columns here, so a
 * test does not have to know the reward moved out into its own table.
 *
 * @extends Factory<CampaignReward>
 */
class CampaignRewardFactory extends Factory
{
    protected $model = CampaignReward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => RewardCampaign::factory(),
            'reward_id' => Reward::factory(),
            'quantity' => 10,
            'validity_days' => 30,
            'is_active' => true,
        ];
    }

    /** An existing catalogue reward rather than a fresh one. */
    public function forReward(Reward $reward): static
    {
        return $this->state(fn (array $attributes) => [
            'reward_id' => $reward->id,
        ]);
    }

    public function discount(float $percentage = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'reward_id' => Reward::factory()->discount($percentage),
        ]);
    }

    public function ofType(RewardType $type, string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'reward_id' => Reward::factory()->ofType($type, $name),
        ]);
    }

    public function product(?Product $product = null): static
    {
        return $this->state(fn (array $attributes) => [
            'reward_id' => Reward::factory()->product($product),
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /** Never lapses, which is legitimate for an installation. */
    public function neverExpiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'validity_days' => null,
        ]);
    }

    /**
     * Paired: only a purchase of one of these products is in the running.
     * Attaching none leaves the reward open to any purchase, which is the
     * default - so this is only ever called with products.
     */
    public function qualifyingFor(Product ...$products): static
    {
        return $this->afterCreating(function (CampaignReward $attachment) use ($products): void {
            $attachment->qualifyingProducts()->sync(
                array_map(fn (Product $product): int => $product->id, $products),
            );
        });
    }
}
