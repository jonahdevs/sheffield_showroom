<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One figure in the KPI row, with the same figure from the window before it.
 *
 * `previous` travels alongside `change` rather than being thrown away once the
 * percentage is worked out: "up 40%" on two visits against five is a different
 * sentence from the same percentage on two hundred, and the tile can only say
 * so if it still has the count.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardStatData extends Data
{
    public function __construct(
        /** What the tile is, for the icon the page hangs on it. */
        public string $key,
        public string $label,
        public int $value,
        public int $previous,
        /**
         * Percent movement on the previous window.
         *
         * Null when the previous window held nothing at all: everything is an
         * increase on zero, and rendering that as an infinite rise is worse
         * than saying there is nothing to compare against.
         */
        public ?float $change,
    ) {}

    public static function compare(string $key, string $label, int $value, int $previous): self
    {
        return new self(
            key: $key,
            label: $label,
            value: $value,
            previous: $previous,
            change: $previous === 0
                ? null
                : round((($value - $previous) / $previous) * 100, 1),
        );
    }
}
