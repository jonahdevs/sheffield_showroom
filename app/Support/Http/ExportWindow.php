<?php

declare(strict_types=1);

namespace App\Support\Http;

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
