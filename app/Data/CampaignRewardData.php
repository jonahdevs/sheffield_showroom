<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\CampaignReward;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One reward definition, with what is left of it.
 *
 * `loaded` is what was put in and never falls; `available`, `claimed` and
 * `void` are counted off the pool and always add up to it. The screen prints
 * all four because an administrator reading "6 left" wants to know whether the
 * other four were won or withdrawn.
 */
#[TypeScript(location: ['App', 'Data'])]
class CampaignRewardData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
        /**
         * The figure as the column holds it, and how to read it. Both raw,
         * because the campaign form has to put them back in the boxes they
         * came out of - and `RewardCampaignController::update` rewrites a
         * draft's rewards wholesale, so a form that could not refill them
         * would silently drop every figure the draft had.
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
    ) {}

    /**
     * @param  array{available: int, claimed: int, void: int}|null  $inventory
     */
    public static function fromModel(CampaignReward $reward, ?array $inventory = null): self
    {
        /* A draft campaign has no pool yet, so everything is still only a
           promise - `loaded` is the quantity and the rest are zero. */
        $inventory ??= ['available' => 0, 'claimed' => 0, 'void' => 0];

        return new self(
            id: $reward->id,
            name: $reward->name,
            description: $reward->description,
            type: $reward->type,
            type_label: $reward->type->label(),
            value: $reward->value,
            value_unit: $reward->value_unit,
            value_label: $reward->readableValue(),
            loaded: $reward->quantity,
            available: $inventory['available'],
            claimed: $inventory['claimed'],
            void: $inventory['void'],
            validity_days: $reward->validity_days,
            terms: $reward->terms,
            is_active: $reward->is_active,
        );
    }
}
