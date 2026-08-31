<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Data\DashboardRangeData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * The stretch of time a list is being read under, resolved off a query string.
 *
 * This began inside `VisitController` and moved here when the rewards list
 * needed the same window at the top of its own page. The names of the windows
 * were already shared - `DashboardRangeData::PRESETS` is the single list of
 * them - but the reading of them was not, and a second copy of the rules below
 * is a second set of edge cases to keep in step. Two screens disagreeing about
 * what a hand-typed `from=2026-13-01` means is exactly the drift that list was
 * written to prevent.
 *
 * Deliberately not `DashboardRangeData`. That object clips an end in the future
 * and caps a span at 366 days, both of which are right for a chart that has to
 * draw a point per day and wrong for a question about what is in the log: a
 * reader asking for everything since 2019 should get it, and an end next week
 * simply means "up to now".
 */
final class DateWindow
{
    /**
     * The window as the name that was clicked and the pair of `Y-m-d` ends it
     * resolves to. Any of the three may be blank: no name where the window was
     * drawn on a calendar, and an open end where none was given.
     *
     * A named window wins outright over any `from`/`to` beside it rather than
     * being merged with them. The two cannot both be honoured - a stale pair of
     * dates left in the URL alongside `range=this_month` would silently narrow
     * the month to something the reader never asked for - and the name is the
     * later, more deliberate choice, since it takes a click on a labelled
     * button to produce one.
     *
     * A pair of dates is corrected rather than refused. This arrives in a query
     * string - off a bookmark, a hand-edited address bar, a link somebody
     * pasted into a chat and mangled - and a reader who asks a nonsensical
     * question of a list should get the list rather than a validation error on
     * a screen with no form on it to show one against. A date that will not
     * parse is dropped, which leaves that end open; a pair the wrong way round
     * is swapped, because the two ends of a window are the same window
     * whichever order they were typed in. An unrecognised name is dropped for
     * the same reason, and falls through to whatever dates came with it.
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
     * The equally long stretch immediately before the chosen window, and how
     * many days that is - what a row of tiles measures its movement against.
     *
     * Null where there is no length to mirror. A window open at the back - no
     * `from` - reaches to the first row ever written, and "the equally long
     * window before that" is not a period, it is a guess. An open far end is
     * different: it means "everything since", which ends today, so it has a
     * length and gets a comparison.
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

        /* An open far end on a window that opens in the future is the one pair
           `fromRequest()` cannot have swapped into order, since it only sees
           one date. A single day is the honest reading of it. */
        if ($end->lessThan($start)) {
            $end = $start;
        }

        /* Inclusive of both ends: a Monday-to-Sunday week is seven days, not
           the six the difference between the dates would give. */
        $days = (int) $start->diffInDays($end) + 1;
        $endsOn = $start->subDay();

        return [
            'days' => $days,
            'from' => $endsOn->subDays($days - 1)->format('Y-m-d'),
            'to' => $endsOn->format('Y-m-d'),
        ];
    }

    /**
     * The window as a sentence, for a closed picker and for the line under a
     * printed export's title. Blank ends read as open ones.
     */
    public static function label(string $from, string $to): string
    {
        return ExportWindow::label(
            $from !== '' ? $from : null,
            $to !== '' ? $to : null,
        );
    }

    /**
     * One end of the window, or null where there is nothing usable to read.
     *
     * `createFromFormat` rather than `parse`, because `parse` is generous to
     * the point of being dangerous here: it reads "yesterday", "+1 week" and a
     * good deal of other prose as dates, so a typo in the query string would
     * come back as a window nobody asked for instead of as no window at all.
     */
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
