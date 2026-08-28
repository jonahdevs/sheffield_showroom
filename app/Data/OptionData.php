<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row in a combobox: what it is, what it reads as, and the line under it
 * that tells two similarly named records apart.
 *
 * Shared rather than one class per list, because a combobox does not care what
 * it is choosing between - it wants a value, a label and something to search
 * on. The `hint` is part of that search: two customers called Mwangi are told
 * apart by the number beside them, so the box has to match on it.
 */
#[TypeScript(location: ['App', 'Data'])]
class OptionData extends Data
{
    public function __construct(
        public int $value,
        public string $label,
        public ?string $hint,
        /**
         * A picture to put before the label, where the record has one. A
         * salesperson recognises a chiller by its photograph well before they
         * read its code.
         */
        public ?string $image_url = null,
    ) {}

    public static function fromProduct(Product $product): self
    {
        return new self(
            value: $product->id,
            label: $product->name,
            hint: $product->sku,
            image_url: $product->imageUrl(),
        );
    }
}
