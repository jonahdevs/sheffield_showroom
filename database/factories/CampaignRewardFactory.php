<?php

namespace Database\Factories;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\CampaignReward;
use App\Models\RewardCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
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
            'name' => 'Free kitchen audit',
            'type' => RewardType::KitchenAudit,
            'description' => null,
            'value' => null,
            'value_unit' => null,
            'quantity' => 10,
            'validity_days' => 30,
            'terms' => null,
            'is_active' => true,
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

    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /** Never lapses, which is a legitimate promise for an installation. */
    public function neverExpiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'validity_days' => null,
        ]);
    }
}
