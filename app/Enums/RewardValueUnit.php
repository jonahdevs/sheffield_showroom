<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

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
