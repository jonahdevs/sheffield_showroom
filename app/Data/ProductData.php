<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProductSource;
use App\Enums\ProductStatus;
use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class ProductData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $sku,
        public ?string $model_number,
        public ?string $image_url,
        public ProductStatus $status,
        public string $status_label,
        public ProductSource $source,
        # True where the website owns the row and a local edit would be undone.
        public bool $is_synced,
        public string $added,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            id: $product->id,
            name: $product->name,
            sku: $product->sku,
            model_number: $product->model_number,
            image_url: $product->imageUrl(),
            status: $product->status,
            status_label: $product->status->label(),
            source: $product->source,
            is_synced: $product->isSynced(),
            added: $product->created_at?->format('j M Y') ?? '',
        );
    }
}
