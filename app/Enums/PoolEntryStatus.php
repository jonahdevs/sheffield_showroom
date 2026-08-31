<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One unit of reward inventory, and whether it is still there to be won.
 *
 * `Claimed` is one-way. A reward that has been won is never returned to the
 * pool, even if the result is later cancelled or expires unredeemed - the
 * customer was told they had won it, and quietly putting it back would let
 * somebody else win the same unit.
 *
 * `Void` is the administrator's way of taking a unit off the table without
 * pretending it was never loaded. Reporting counts it, which is what makes
 * `loaded = available + claimed + void` reconcile.
 */
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
