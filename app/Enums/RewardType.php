<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a reward actually is.
 *
 * A closed list rather than free text, because the reporting screen groups by
 * it and the customer-facing card needs a glyph for each one. A showroom that
 * invents a sixth kind of reward adds a case here, which is the point: the
 * front end then has to be told how to draw it rather than silently rendering
 * a blank.
 *
 * `Discount` is the only one carrying a number worth reading - the rest are
 * services, whose worth is in their terms.
 */
#[TypeScript]
enum RewardType: string
{
    case Discount = 'discount';
    case DrawingLayout = 'drawing_layout';
    case KitchenAudit = 'kitchen_audit';
    case ComplimentaryService = 'complimentary_service';
    case Installation = 'installation';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Discount',
            self::DrawingLayout => 'Drawing & layout',
            self::KitchenAudit => 'Kitchen audit',
            self::ComplimentaryService => 'Complimentary service',
            self::Installation => 'Installation',
        };
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
