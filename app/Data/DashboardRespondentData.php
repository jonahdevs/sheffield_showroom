<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one person on the floor did over the window.
 *
 * Grouped by the respondent's name rather than by an account, because that is
 * what the visit stores: whoever took the call is written down and is not
 * always somebody with a login. Visits and customers are counted separately
 * on purpose - forty visits from twelve people is a different week from forty
 * visits from forty.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardRespondentData extends Data
{
    public function __construct(
        public string $name,
        public int $visits,
        /** Distinct customers behind those visits. */
        public int $customers,
        /** Visits with a follow-up date pencilled in against them. */
        public int $follow_ups,
    ) {}
}
