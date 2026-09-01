<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum RewardType: string
{
    case Discount = 'discount';
    case Product = 'product';
    case DrawingLayout = 'drawing_layout';
    case KitchenAudit = 'kitchen_audit';
    case ComplimentaryService = 'complimentary_service';
    case Installation = 'installation';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Discount',
            self::Product => 'Product',
            self::DrawingLayout => 'Drawing & layout',
            self::KitchenAudit => 'Kitchen audit',
            self::ComplimentaryService => 'Complimentary service',
            self::Installation => 'Installation',
        };
    }

    # The one type that names a row in `products`: `RewardRequest` requires a
    # `product_id` for it and refuses one for every other type.
    public function isProduct(): bool
    {
        return $this === self::Product;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
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
