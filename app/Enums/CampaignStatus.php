<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a reward campaign is in its life.
 *
 * Only `Active` hands out rewards. The rest are the states around that: one
 * being written, one waiting for its start date, one stopped on purpose, and
 * two ways of being over.
 *
 * `Draft` is the only state whose reward quantities may still be reshaped.
 * Once a campaign is published its pool is controlled inventory - a hundred
 * rewards means a hundred, and changing that number afterwards changes what
 * people were told they were playing for.
 */
#[TypeScript]
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the pool has been generated and is therefore no longer the
     * administrator's to rewrite.
     */
    public function isPublished(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Whether a campaign in this state has finished for good. A finished
     * campaign keeps its history - the results, the redemptions and the
     * reporting behind them all outlive it.
     */
    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
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
