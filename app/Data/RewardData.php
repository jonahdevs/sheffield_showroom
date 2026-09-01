<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RewardType;
use App\Enums\RewardValueUnit;
use App\Models\Reward;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class RewardData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public RewardType $type,
        public string $type_label,
        public ?int $product_id,
        public ?string $product_name,
        public ?string $product_image_url,
        public ?string $value,
        public ?RewardValueUnit $value_unit,
        public ?string $value_label,
        public ?string $terms,
        # Copied down onto the attachment when a campaign takes the reward and
        # never read through afterwards, so editing it moves no live deadline.
        public ?int $default_validity_days,
        public bool $is_active,
        public int $campaigns_count,
        public bool $can_delete,
        public string $added,
    ) {}

    public static function fromModel(Reward $reward): self
    {
        # The fallback query is one per row; the list eager-counts instead.
        $attachments = $reward->attachments_count ?? $reward->attachments()->count();

        return new self(
            id: $reward->id,
            name: $reward->readableName(),
            description: $reward->description,
            type: $reward->type,
            type_label: $reward->type->label(),
            product_id: $reward->product_id,
            product_name: $reward->product?->name,
            product_image_url: $reward->product?->imageUrl(),
            value: $reward->value,
            value_unit: $reward->value_unit,
            value_label: $reward->readableValue(),
            terms: $reward->terms,
            default_validity_days: $reward->default_validity_days,
            is_active: $reward->is_active,
            campaigns_count: $attachments,
            can_delete: $attachments === 0,
            added: $reward->created_at?->format('j M Y') ?? '',
        );
    }
}
