<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `Claimed` is one-way: a won unit never returns to the pool. `Void` takes an
# unwon unit off the table and still counts as loaded: `loaded = available + claimed + void`.
#[TypeScript]
enum PoolEntryStatus: string
{
    case Available = 'available';
    case Claimed = 'claimed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Claimed => 'Claimed',
            self::Void => 'Void',
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
