<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class DashboardStatData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public int $previous,
        # Null when the previous window held nothing: everything is an increase
        # on zero, and an infinite rise is worse than nothing to compare.
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
