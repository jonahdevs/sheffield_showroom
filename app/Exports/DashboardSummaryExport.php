<?php

declare(strict_types=1);

namespace App\Exports;

use App\Data\DashboardProductInterestData;
use App\Data\DashboardRangeData;
use App\Data\DashboardRespondentData;
use App\Data\DashboardSliceData;
use App\Data\DashboardStatData;
use App\Data\DashboardTrendPointData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The dashboard as a file: every panel on it, in the order it is read on
 * screen.
 *
 * Handed the figures the page was rendered from rather than re-running the
 * queries. A download taken from a screen has to say what the screen said, and
 * a second pass at the database an instant later is how a total in a
 * spreadsheet ends up disagreeing with the tile it was taken from.
 *
 * One long table rather than a sheet per panel, because this is the shape all
 * three formats can carry: a CSV has no second sheet, and the PDF is typeset
 * from the CSV.
 */
class DashboardSummaryExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<int, DashboardStatData>  $stats
     * @param  array<int, DashboardTrendPointData>  $trend
     * @param  array<int, DashboardSliceData>  $purposes
     * @param  array<int, DashboardSliceData>  $sources
     * @param  array<int, DashboardProductInterestData>  $products
     * @param  array<int, DashboardRespondentData>  $respondents
     */
    public function __construct(
        private DashboardRangeData $range,
        private array $stats,
        private array $trend,
        private array $purposes,
        private array $sources,
        private array $products,
        private array $respondents,
    ) {}

    public function title(): string
    {
        return 'Dashboard';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Section', 'Item', 'Visits', 'Detail'];
    }

    /**
     * @return array<int, array<int, string|int|float>>
     */
    public function array(): array
    {
        return [
            ...$this->summaryRows(),
            ...$this->sliceRows('Visits by purpose', $this->purposes),
            ...$this->sliceRows('Visits by source', $this->sources),
            ...$this->productRows(),
            ...$this->respondentRows(),
            ...$this->trendRows(),
        ];
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function summaryRows(): array
    {
        $rows = [[
            'Period',
            $this->range->label,
            '',
            $this->range->days.' days ('.$this->range->from.' to '.$this->range->to.')',
        ]];

        foreach ($this->stats as $stat) {
            $rows[] = [
                'Summary',
                $stat->label,
                $stat->value,
                $stat->change === null
                    ? 'No previous period to compare'
                    : sprintf(
                        '%s%s%% on %d over the previous %d days',
                        $stat->change > 0 ? '+' : '',
                        $stat->change,
                        $stat->previous,
                        $this->range->days,
                    ),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, DashboardSliceData>  $slices
     * @return array<int, array<int, string|int>>
     */
    private function sliceRows(string $section, array $slices): array
    {
        return array_map(
            fn (DashboardSliceData $slice) => [
                $section,
                $slice->label,
                $slice->count,
                $slice->share.'% of the period',
            ],
            $slices,
        );
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function productRows(): array
    {
        return array_map(
            fn (DashboardProductInterestData $product) => [
                'Top product interests',
                $product->name,
                $product->visits,
                '',
            ],
            $this->products,
        );
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function respondentRows(): array
    {
        return array_map(
            fn (DashboardRespondentData $person) => [
                'Respondent performance',
                $person->name,
                $person->visits,
                $person->customers.' customers, '.$person->follow_ups.' follow-ups',
            ],
            $this->respondents,
        );
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function trendRows(): array
    {
        return array_map(
            fn (DashboardTrendPointData $point) => [
                'Visits per day',
                $point->date,
                $point->visits,
                '',
            ],
            $this->trend,
        );
    }
}
