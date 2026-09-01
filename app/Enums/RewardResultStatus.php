<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# A result is permanent: never deleted, and its pool entry never returned.
#[TypeScript]
enum RewardResultStatus: string
{
    case Unredeemed = 'unredeemed';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unredeemed => 'Not yet used',
            self::Redeemed => 'Redeemed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isRedeemable(): bool
    {
        return $this === self::Unredeemed;
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
