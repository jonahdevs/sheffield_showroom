<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# `share` is computed here, not in the legend: the legend only sees non-empty
# wedges, and a percentage off that shortened list sums to a hundred and lies.
#[TypeScript(location: ['App', 'Data'])]
class DashboardSliceData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public int $count,
        public float $share,
    ) {}
}
