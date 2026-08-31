<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One customer's single opportunity to shuffle.
 *
 * `Pending` is the only state a shuffle can run from, and the transition out
 * of it happens inside the same transaction that claims the reward. That is
 * what stops a refreshed page, a double tap, or two phones on the same QR from
 * producing two rewards: the second attempt finds a session that is no longer
 * pending.
 *
 * A session whose campaign has run out of rewards stays `Pending` rather than
 * failing. Nothing was won, so nothing should be spent - the customer keeps
 * their turn and staff can settle it.
 */
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
