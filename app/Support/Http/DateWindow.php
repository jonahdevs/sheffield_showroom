<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Data\DashboardRangeData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

# Deliberately not `DashboardRangeData`: that clips a future end and caps a span at
# 366 days — right for a trend line, wrong for a question about what is in the log.
final class DateWindow
{
    /**
     * A named window wins outright over any `from`/`to` beside it, never merged
     * with them: stale dates left in the URL would silently narrow the month.
     * A bad pair is corrected rather than refused — this arrives in a query
     * string, and there is no form on the page to show an error against.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function fromRequest(Request $request): array
    {
        $preset = $request->string('range')->toString();

        if (DashboardRangeData::isPreset($preset)) {
            $range = DashboardRangeData::preset($preset);

            return [$preset, $range->from, $range->to];
        }

        $from = self::date($request->string('from')->toString());
        $to = self::date($request->string('to')->toString());

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            '',
            $from?->format('Y-m-d') ?? '',
            $to?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * Null where there is no length to mirror: a window with no `from` reaches
     * to the first row ever written, so the stretch before it is a guess.
     *
     * @return array{days: int, from: string, to: string}|null
     */
    public static function preceding(string $from, string $to): ?array
    {
        if ($from === '') {
            return null;
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = $to === ''
            ? CarbonImmutable::today()
            : CarbonImmutable::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            $end = $start;
        }

        # Inclusive of both ends: Monday to Sunday is seven days, not the six
        # the difference between the dates gives.
        $days = (int) $start->diffInDays($end) + 1;
        $endsOn = $start->subDay();

        return [
            'days' => $days,
            'from' => $endsOn->subDays($days - 1)->format('Y-m-d'),
            'to' => $endsOn->format('Y-m-d'),
        ];
    }

    public static function label(string $from, string $to): string
    {
        return ExportWindow::label(
            $from !== '' ? $from : null,
            $to !== '' ? $to : null,
        );
    }

    # `createFromFormat`, never `parse`: `parse` reads "yesterday" and "+1 week" as
    # dates, so a typo would come back as a window nobody asked for.
    private static function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
