<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `Pending` is the only state a shuffle runs from, and the move out of it happens
# inside the claiming transaction — that is what stops a double tap winning twice.
#[TypeScript]
enum ShuffleSessionStatus: string
{
    case Pending = 'pending';
    case Shuffled = 'shuffled';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not yet used',
            self::Shuffled => 'Shuffled',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
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
