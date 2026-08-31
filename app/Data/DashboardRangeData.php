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
     * Every window that has a name, in the order a control should offer them.
     *
     * Ordered shortest-first rather than alphabetically, because a rail of them
     * is read as a dial from "just now" outwards and a list that jumped from
     * last month back to today would have to be searched instead of aimed at.
     *
     * The list is public because the visits log borrows this same vocabulary
     * for its own picker. Two screens naming windows differently - "week to
     * date" here, "this week" there, resolving to different days - is exactly
     * the drift a shared list prevents, and it is cheaper to share the six
     * names than to reconcile two sets of dates later.
     *
     * @var array<string, string>
     */
    public const PRESETS = [
        'today' => 'Today',
        'this_week' => 'This week',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'last_90_days' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ];

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
     * The named windows, for a control that offers them as buttons.
     *
     * Shaped like every other option list the pages are handed - `VisitPurpose::options()`,
     * `PageSize::OPTIONS` - so a picker consumes it without a special case, and
     * so the labels are written once here rather than typed again in whichever
     * templates happen to show them.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::PRESETS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * Whether a string off a query string names a window.
     *
     * Worth asking before calling `preset()`, because that method answers
     * anything at all with the default week - which is right for the dashboard,
     * where some window must be drawn, and wrong anywhere a missing or mangled
     * name should mean "no window was named" rather than "here is a week you
     * did not ask for".
     */
    public static function isPreset(string $preset): bool
    {
        return array_key_exists($preset, self::PRESETS);
    }

    /**
     * A window named rather than drawn.
     *
     * All three screens that read by date - the dashboard, the visits log and
     * the list of won rewards - offer these as buttons beside the calendar, so
     * the common questions are one click rather than two dates typed twice.
     * They would still be needed if none of them did: the default window is one
     * of them, and a link somebody kept - `?range=last_30_days` - should open
     * the month it promised rather than silently showing a week.
     */
    public static function preset(string $preset): self
    {
        $today = CarbonImmutable::today();

        return match ($preset) {
            /* A single day, so `previous()` gives yesterday - which is what the
               visits log's old "Today" tile was compared against, kept reachable
               now that the tile itself follows the picker. */
            'today' => self::between($preset, $today, $today),
            /* Week to date rather than a rolling seven days: the two are
               different questions, and `last_7_days` sits immediately below it
               for whoever wanted the other one. */
            'this_week' => self::between($preset, $today->startOfWeek(), $today),
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
