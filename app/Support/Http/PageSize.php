<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

final class PageSize
{
    /**
     * @var array<int, int>
     */
    public const OPTIONS = [10, 25, 50, 100];

    /**
     * @param  array<int, int>  $options
     */
    public static function from(Request $request, array $options = self::OPTIONS): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, $options, true)
            ? $requested
            : $options[0];
    }
}
