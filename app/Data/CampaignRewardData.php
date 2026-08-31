<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\CampaignReward;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One reward as a campaign holds it, with what is left of it.
 *
 * Flattened on purpose. The reward itself lives in the catalogue now, but the
 * campaign screen asks about one drawer of one promotion - so what the thing
 * is and how much of it remains arrive together, the way somebody reads them
 * off a row. `reward_id` is what the form posts back; the descriptive fields
 * beside it are read-only copies of the catalogue and editing them there would
 * be editing the wrong record.
 *
 * `loaded` is what was put in and never falls; `available`, `claimed` and
 * `void` are counted off the pool and always add up to it. The screen prints
 * all four because an administrator reading "6 left" wants to know whether the
 * other four were won or withdrawn.
 */
#[TypeScript(location: ['App', 'Data'])]
class CampaignRewardData extends Data
{
    /**
     * @param  array<int, array{id: int, name: string}>  $qualifying_products
     */
    public function __construct(
        public int $id,
        /** The catalogue row this attachment points at. */
        public int $reward_id,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
        /**
         * The item won, when the reward is a thing off the floor rather than a
         * discount or a service. Null for every other type.
         */
        public ?int $product_id,
        public ?string $product_name,
        /**
         * The figure as the column holds it, and how to read it. Both raw,
         * because the campaign form has to put them back in the boxes they
         * came out of.
         */
        public ?string $value,
        public ?RewardValueUnit $value_unit,
        /** The same figure as somebody reads it: "10%", "KSh 5,000.00". */
        public ?string $value_label,
        public int $loaded,
        public int $available,
        public int $claimed,
        public int $void,
        public ?int $validity_days,
        public ?string $terms,
        public bool $is_active,
        /**
         * What somebody must have bought to be in the running for this.
         *
         * Empty is the common case and means any purchase qualifies - the
         * screen says so rather than leaving the column blank, because a blank
         * reads as "not set up yet".
         */
        public array $qualifying_products,
    ) {}

    /**
     * @param  array{available: int, claimed: int, void: int}|null  $inventory
     */
    public static function fromModel(CampaignReward $attachment, ?array $inventory = null): self
    {
        /* A draft campaign has no pool yet, so everything is still only a
           promise - `loaded` is the quantity and the rest are zero. */
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
