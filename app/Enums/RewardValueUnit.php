<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How to read the number on a reward.
 *
 * `10` means nothing on its own: ten per cent off is not ten shillings off.
 * The column is nullable alongside `value` itself, because most rewards here
 * are services whose worth is written in their terms rather than counted.
 */
#[TypeScript]
enum RewardValueUnit: string
{
    case Percentage = 'percentage';
    case Currency = 'currency';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Currency => 'Shillings',
        };
    }

    /**
     * The value as somebody reads it off a card.
     *
     * A single currency is assumed throughout, the same way the customer form
     * assumes Kenya: a showroom on one floor in Nairobi does not price in two.
     */
    public function format(float $value): string
    {
        return match ($this) {
            self::Percentage => rtrim(rtrim(number_format($value, 2), '0'), '.').'%',
            self::Currency => 'KSh '.number_format($value, 2),
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $unit) => ['value' => $unit->value, 'label' => $unit->label()],
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
