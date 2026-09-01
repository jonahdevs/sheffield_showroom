<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\InterestLevel;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# The first four fields must stay identical to `OptionData`, in order: the
# combobox takes this in its place.
#[TypeScript(location: ['App', 'Data'])]
class ProductOptionData extends Data
{
    public function __construct(
        public int $value,
        public string $label,
        public ?string $hint,
        public ?string $image_url,
        public ?string $model_number,
        public int $quantity,
        public ?InterestLevel $interest_level,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            value: $product->id,
            label: $product->name,
            hint: $product->sku,
            image_url: $product->imageUrl(),
            model_number: $product->model_number,
            quantity: 1,
            interest_level: null,
        );
    }

    # Falls back to `Medium` for a row attached before interest was asked for.
    public static function fromVisitProduct(Product $product): self
    {
        $level = $product->pivot?->interest_level;

        return new self(
            value: $product->id,
            label: $product->name,
            hint: $product->sku,
            image_url: $product->imageUrl(),
            model_number: $product->model_number,
            quantity: (int) ($product->pivot?->quantity ?? 1),
            interest_level: $level === null
                ? InterestLevel::Medium
                : InterestLevel::from((string) $level),
        );
    }
}
