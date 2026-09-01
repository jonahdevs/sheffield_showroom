<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class DashboardProductInterestData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $image_url,
        public int $visits,
    ) {}

    public static function fromModel(Product $product, int $visits): self
    {
        return new self(
            id: $product->id,
            name: $product->name,
            image_url: $product->imageUrl(),
            visits: $visits,
        );
    }
}
