<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a product row came from.
 *
 * The distinction matters the day a sync runs: a row somebody typed in here is
 * theirs and must survive, and a row the website put here is the website's to
 * replace.
 */
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
