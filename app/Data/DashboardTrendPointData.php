<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One day on the trend line.
 *
 * Every day in the window gets a point, including the ones nobody came in on.
 * A line drawn only over the days that have rows joins Monday to Thursday as
 * though Tuesday and Wednesday never happened, which reads as a quiet week
 * rather than the closed shop it was.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardTrendPointData extends Data
{
    public function __construct(
        /** `Y-m-d`, for the tooltip and for anything that has to sort. */
        public string $date,
        /** The axis tick, already in the form the chart shows it. */
        public string $label,
        public int $visits,
    ) {}
}
