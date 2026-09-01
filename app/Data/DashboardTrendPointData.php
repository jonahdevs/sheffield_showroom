<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# Every day in the window gets a point, empty ones included: a line drawn only
# over days that have rows joins Monday to Thursday and reads as a quiet week.
#[TypeScript(location: ['App', 'Data'])]
class DashboardTrendPointData extends Data
{
    public function __construct(
        public string $date,
        public string $label,
        public int $visits,
    ) {}
}
