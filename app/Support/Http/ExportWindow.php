<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The date filter a list was read under, in words.
 *
 * A printed export says only what its title says, so two files pulled a month
 * apart off the same screen are indistinguishable once they are on paper. The
 * line under the title is what tells them apart - and where a screen carries no
 * date filter at all, saying so is itself worth printing: "All dates" is the
 * difference between a full history and a month somebody happened to pull.
 */
final class ExportWindow
{
    public static function label(?string $from, ?string $to): string
    {
        return match (true) {
            $from !== null && $to !== null => "{$from} to {$to}",
            $from !== null => "From {$from}",
            $to !== null => "Up to {$to}",
            default => 'All dates',
        };
    }
}
