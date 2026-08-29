<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product and how many visits it was shown on.
 *
 * The picture comes with it because that is how the floor recognises a
 * product - the same reason the catalogue is a grid of tiles and not a table.
 * A bar chart labelled only by name asks a salesperson to read where they
 * would otherwise glance.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardProductInterestData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $image_url,
        /** Visits in the window that named this product. */
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
