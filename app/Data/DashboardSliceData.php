<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One wedge of a donut: a purpose or a source, how many visits it accounts
 * for, and what portion of the window that is.
 *
 * The share is worked out here rather than in the legend because the legend
 * only ever sees the wedges that have something in them - a purpose nobody
 * came in for is left out entirely - and a percentage computed off that
 * shortened list would add up to a hundred while being wrong.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardSliceData extends Data
{
    public function __construct(
        /** The enum's own value, so the wedge keeps a stable colour. */
        public string $value,
        public string $label,
        public int $count,
        /** Percent of the window, to one decimal place. */
        public float $share,
    ) {}
}
