<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How keen somebody was on one product they were shown.
 *
 * Recorded per product rather than per visit: a customer shown four things is
 * rarely equally interested in all four, and which one they leaned towards is
 * the whole point of writing the visit up. `Medium` is the default so adding a
 * product to the list costs nobody a decision they do not want to make.
 */
#[TypeScript]
enum InterestLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $level) => ['value' => $level->value, 'label' => $level->label()],
            self::cases(),
        );
    }
}
