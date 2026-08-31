<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether a sale counts yet.
 *
 * Only `Completed` earns a shuffle. A purchase recorded on the floor is
 * normally complete the moment it is typed in - somebody has paid and walked
 * out with something - so that is the default. `Pending` is for a sale still
 * being settled, and is deliberately not eligible: a reward handed out against
 * a payment that later falls through cannot be taken back.
 */
#[TypeScript]
enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether a purchase in this state can earn a shuffle. */
    public function isQualifying(): bool
    {
        return $this === self::Completed;
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
