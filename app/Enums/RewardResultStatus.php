<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What happened to a reward after it was won.
 *
 * A result is permanent. It is never deleted and its pool entry is never
 * returned, so these four states are the whole history of one reward: it is
 * waiting to be used, it was used, its validity ran out, or somebody
 * cancelled it.
 *
 * `Expired` is set by `rewards:expire`, which reads `expires_at` rather than
 * recomputing it from the reward definition - the date is stamped when the
 * reward is won, so an administrator lengthening `validity_days` afterwards
 * does not quietly move a deadline somebody was already given.
 */
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

    /**
     * Whether the reward can still be handed over. Only an unredeemed one can,
     * and `RewardRedemptionService` is where that is enforced.
     */
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
