<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `hint` is searched as well as shown - two customers called Mwangi are told
# apart by the number beside them.
#[TypeScript(location: ['App', 'Data'])]
class OptionData extends Data
{
    public function __construct(
        public int $value,
        public string $label,
        public ?string $hint,
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
