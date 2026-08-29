<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\InterestLevel;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product as the visit form's picker and its list of picks both need it.
 *
 * `OptionData` plus what the table under the box shows: the model number a
 * customer asks after, how many of it they were after, and how keen they were
 * on it. The interest is null in the catalogue the box chooses from and set on
 * the rows already picked - the same shape either way, so a pick becomes a row
 * without being rebuilt.
 *
 * The first four fields match `OptionData` exactly so the combobox can take
 * this in its place.
 */
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
            /* One, until somebody says otherwise. Nobody walks in after none
               of something. */
            quantity: 1,
            interest_level: null,
        );
    }

    /**
     * A product as it was shown on one visit, interest and all.
     *
     * Read off the pivot the relation carries, falling back to `Medium` for a
     * row attached before the question was asked.
     */
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
