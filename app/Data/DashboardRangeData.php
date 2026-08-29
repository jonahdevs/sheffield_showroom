<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * The window every panel on the dashboard is measured over.
 *
 * One object rather than a pair of dates on the request: the deltas need the
 * window before this one, and "the same length, ending the day before" is a
 * rule that has to be written once. A panel that computed its own previous
 * period would drift the moment a preset was added.
 *
 * Named windows and a hand-picked pair land in the same shape, so nothing
 * downstream has to know which of the two the reader chose.
 */
#[TypeScript(location: ['App', 'Data'])]
class DashboardRangeData extends Data
{
    /** What the control falls back to, and what an unrecognised preset lands on. */
    public const DEFAULT = 'last_7_days';

    /** A window picked on the calendar rather than named. */
    public const CUSTOM = 'custom';

    /**
     * The longest window the dashboard will draw.
     *
     * The trend line carries a point per day, and a hand-typed query string can
     * ask for a decade. Two years of points is already a smear; anything past
     * this is a chart nobody can read paid for with a query nobody wanted.
     */
    private const MAX_DAYS = 366;

    public function __construct(
        public string $preset,
        /** Inclusive, `Y-m-d`. */
        public string $from,
        /** Inclusive, `Y-m-d`. */
        public string $to,
        /** The window written out, for the control and for a panel's empty state. */
        public string $label,
        /** How many days it covers, which is also how far back `previous()` reaches. */
        public int $days,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $preset = $request->string('range')->toString();

        if ($preset !== self::CUSTOM) {
            return self::preset($preset);
        }

        return self::custom(
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        );
    }

    /**
     * A window named rather than drawn.
     *
     * The control on the page is a calendar and offers none of these; they stay
     * because the default window is one of them, and because a link somebody
     * kept - `?range=last_30_days` - should still open the month it promised
     * rather than silently showing a week.
     */
    public static function preset(string $preset): self
    {
        $today = CarbonImmutable::today();

        return match ($preset) {
            'last_30_days' => self::between($preset, $today->subDays(29), $today),
            'last_90_days' => self::between($preset, $today->subDays(89), $today),
            'this_month' => self::between($preset, $today->startOfMonth(), $today),
            'last_month' => self::between(
                $preset,
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ),
            default => self::between(self::DEFAULT, $today->subDays(6), $today),
        };
    }

    /**
     * A window picked on the calendar.
     *
     * Anything that does not describe a window the showroom could have had -
     * a date that will not parse, a pair the wrong way round, an end in the
     * future, a span longer than a year - is corrected rather than refused.
     * This is a query string, and the reader gets a dashboard either way.
     */
    public static function custom(string $from, string $to): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null || $end === null) {
            return self::preset(self::DEFAULT);
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $today = CarbonImmutable::today();

        if ($end->greaterThan($today)) {
            $end = $today;
        }

        if ($start->greaterThan($end)) {
            $start = $end;
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return self::between(self::CUSTOM, $start, $end);
    }

    /**
     * The equally long window immediately before this one, which is what every
     * figure in the KPI row is compared against.
     */
    public function previous(): self
    {
        $endsOn = $this->startsAt()->subDay();

        return self::between($this->preset, $endsOn->subDays($this->days - 1), $endsOn);
    }

    /**
     * The first instant inside the window. A visit is stored to the minute, so
     * a comparison against the bare date would drop everything logged after
     * midnight on the closing day.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from)->startOfDay();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to)->endOfDay();
    }

    private static function between(string $preset, CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            preset: $preset,
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            label: self::describe($from, $to),
            /* Inclusive of both ends: a Monday-to-Sunday week is seven days,
               not the six the difference between the dates would give. */
            days: (int) $from->diffInDays($to) + 1,
        );
    }

    private static function parse(string $date): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The window as somebody would say it. The year is written once, on the
     * closing date, unless the window straddles two of them.
     */
    private static function describe(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        return $from->year === $to->year
            ? $from->format('j M').' - '.$to->format('j M Y')
            : $from->format('j M Y').' - '.$to->format('j M Y');
    }
}
