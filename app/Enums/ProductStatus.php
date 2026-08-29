<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether a product belongs on the showroom floor today.
 *
 * Three of these mirror the website: it publishes a product, holds one back as
 * a draft, or withdraws one altogether. `Inactive` has no counterpart there and
 * never will - it is what somebody standing on the floor sets when a product is
 * still sold but not worth showing this month, and the sync is written to leave
 * it alone precisely because the website cannot know about it. See
 * `CatalogueSync::status()`, which is where that promise is kept.
 */
#[TypeScript]
enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
