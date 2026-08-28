<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProductSource;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product as both the list and the form need it. There are only three fields
 * on the record, so one object serves both rather than splitting a row object
 * off a form object for no gain.
 */
#[TypeScript(location: ['App', 'Data'])]
class ProductData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $sku,
        public ?string $image_url,
        public ProductSource $source,
        /** Whether the website owns this row, and editing it here would be undone. */
        public bool $is_synced,
        public string $added,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            id: $product->id,
            name: $product->name,
            sku: $product->sku,
            image_url: $product->imageUrl(),
            source: $product->source,
            is_synced: $product->isSynced(),
            added: $product->created_at?->format('j M Y') ?? '',
        );
    }
}
