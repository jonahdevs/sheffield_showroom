<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class RewardExpiringRowData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $customer_name,
        public string $expires_on,
        # Truncated, never rounded up - nought means it lapses today, and
        # rounding a day and a half up to two costs a customer their reward.
        public int $days_left,
    ) {}
}
