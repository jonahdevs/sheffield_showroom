<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class PermissionOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        /** False when the person editing does not hold it themselves. */
        public bool $grantable,
    ) {}
}
