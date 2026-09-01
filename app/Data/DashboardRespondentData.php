<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# Grouped by the respondent's name, not by an account: whoever took the call
# is written down and is not always somebody with a login.
#[TypeScript(location: ['App', 'Data'])]
class DashboardRespondentData extends Data
{
    public function __construct(
        public string $name,
        public int $visits,
        public int $customers,
        public int $follow_ups,
    ) {}
}
