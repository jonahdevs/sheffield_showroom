<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

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

    # Published means the pool has been generated: its quantities are no longer
    # the administrator's to rewrite.
    public function isPublished(): bool
    {
        return $this !== self::Draft;
    }

    # Stopped: handing out nothing, whether it was seen through or called off.
    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    # Cancelled is called off, not finished - the pool it was published with is
    # untouched, so it can be restarted. Completed is the one state nothing
    # moves out of, and `CampaignService::activate` refuses only this.
    public function isFinal(): bool
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
