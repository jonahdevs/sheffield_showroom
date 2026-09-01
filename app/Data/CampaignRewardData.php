<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\CampaignReward;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `reward_id` is what the form posts back; the descriptive fields beside it are
# read-only copies of the catalogue. `loaded` never falls, and
# `loaded = available + claimed + void`.
#[TypeScript(location: ['App', 'Data'])]
class CampaignRewardData extends Data
{
    /**
     * @param  array<int, array{id: int, name: string}>  $qualifying_products
     */
    public function __construct(
        public int $id,
        public int $reward_id,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
        public ?int $product_id,
        public ?string $product_name,
        public ?string $value,
        public ?RewardValueUnit $value_unit,
        public ?string $value_label,
        public int $loaded,
        public int $available,
        public int $claimed,
        public int $void,
        public ?int $validity_days,
        public ?string $terms,
        public bool $is_active,
        # Empty is the common case and means any purchase qualifies.
        public array $qualifying_products,
    ) {}

    /**
     * @param  array{available: int, claimed: int, void: int}|null  $inventory
     */
    public static function fromModel(CampaignReward $attachment, ?array $inventory = null): self
    {
        # A draft campaign has no pool yet: `loaded` is the quantity, rest zero.
        $inventory ??= ['available' => 0, 'claimed' => 0, 'void' => 0];

        $reward = $attachment->reward;

        return new self(
            id: $attachment->id,
            reward_id: $attachment->reward_id,
            name: $reward->readableName(),
            description: $reward->description,
            type: $reward->type,
            type_label: $reward->type->label(),
            product_id: $reward->product_id,
            product_name: $reward->product?->name,
            value: $reward->value,
            value_unit: $reward->value_unit,
            value_label: $reward->readableValue(),
            loaded: $attachment->quantity,
            available: $inventory['available'],
            claimed: $inventory['claimed'],
            void: $inventory['void'],
            validity_days: $attachment->validity_days,
            terms: $reward->terms,
            is_active: $attachment->is_active,
            qualifying_products: $attachment->qualifyingProducts
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                ])
                ->values()
                ->all(),
        );
    }
}
