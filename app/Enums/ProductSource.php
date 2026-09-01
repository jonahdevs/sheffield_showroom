<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# A `Manual` row was typed in here and must survive a sync; a `Website` row is
# the website's to replace.
#[TypeScript]
enum ProductSource: string
{
    case Manual = 'manual';
    case Website = 'website';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Added here',
            self::Website => 'From the website',
        };
    }
}
