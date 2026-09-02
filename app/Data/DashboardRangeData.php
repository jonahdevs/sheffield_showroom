<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

#[TypeScript(location: ['App', 'Data'])]
class DashboardRangeData extends Data
{
    public const DEFAULT = 'last_7_days';

    public const CUSTOM = 'custom';

    # The only declaration of the named windows; the visits log reads the same
    # vocabulary. Do not resolve a named window anywhere else.
    /** @var array<string, string> */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'last_year' => 'Last year',
    ];

    private const MAX_DAYS = 366;

    public function __construct(
        public string $preset,
        # `from` and `to` are inclusive, `Y-m-d`.
        public string $from,
        public string $to,
        public string $label,
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

    # Ask this before calling `preset()`: that method answers an unrecognised
    # name with the default week, which is wrong wherever a missing name should
    # mean "no window".
    public static function isPreset(string $preset): bool
    {
        return array_key_exists($preset, self::PRESETS);
    }

    public static function preset(string $preset): self
    {
        $today = CarbonImmutable::today();

        return match ($preset) {
            'today' => self::between($preset, $today, $today),
            'yesterday' => self::between($preset, $today->subDay(), $today->subDay()),
            # Week to date, not a rolling seven days; `last_7_days` is the other one.
            'this_week' => self::between($preset, $today->startOfWeek(), $today),
            'this_month' => self::between($preset, $today->startOfMonth(), $today),
            'last_month' => self::between(
                $preset,
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ),
            'this_year' => self::between($preset, $today->startOfYear(), $today),
            'last_year' => self::between(
                $preset,
                $today->subYearNoOverflow()->startOfYear(),
                $today->subYearNoOverflow()->endOfYear(),
            ),
            default => self::between(self::DEFAULT, $today->subDays(6), $today),
        };
    }

    # A nonsensical pair is clipped rather than refused - this is a query
    # string, and the reader gets a dashboard either way.
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

    public function previous(): self
    {
        $endsOn = $this->startsAt()->subDay();

        return self::between($this->preset, $endsOn->subDays($this->days - 1), $endsOn);
    }

    # Visits are stored to the minute, so both ends widen to the whole day.
    # Comparing against the bare date drops everything logged after midnight.
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
            # Inclusive of both ends: a Monday-to-Sunday week is seven days.
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
