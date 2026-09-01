<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `Inactive` is local-only and has no counterpart on the website, so
# `CatalogueSync::status()` must never set or overwrite it.
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
