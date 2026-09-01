<?php

declare(strict_types=1);

namespace App\Exports;

use App\Data\VisitRowData;
use App\Enums\VisitReport;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * A `VisitReport` narrows which fields are printed and never which rows are -
 * the query arrives already filtered and already authorised.
 *
 * @implements WithMapping<Visit>
 */
class VisitExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  Builder<Visit>  $query  Already filtered and authorised.
     * @param  VisitReport  $report  Which columns to print.
     */
    public function __construct(
        private Builder $query,
        private VisitReport $report = VisitReport::Full,
    ) {}

    public function title(): string
    {
        return $this->report->title();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_values($this->report->columns());
    }

    /**
     * @return Builder<Visit>
     */
    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @param  Visit  $row
     * @return array<int, mixed>
     */
    public function map(mixed $row): array
    {
        $visit = VisitRowData::fromModel($row);

        return array_map(
            fn (string $column): mixed => $this->value($column, $row, $visit),
            array_keys($this->report->columns()),
        );
    }

    /**
     * The phone as text, or a spreadsheet eats its leading zero. Which column
     * that is depends on the report, so it is found by name rather than
     * written down.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $position = array_search('customer_phone', array_keys($this->report->columns()), true);

        if ($position === false) {
            return [];
        }

        return [
            chr(65 + (int) $position) => NumberFormat::FORMAT_TEXT,
        ];
    }

    private function value(string $column, Visit $row, VisitRowData $visit): mixed
    {
        return match ($column) {
            'id' => $visit->id,
            'customer_name' => $visit->customer_name,
            'customer_company' => $visit->customer_company ?? '',
            'customer_type' => $visit->customer_type->label(),
            'customer_phone' => $visit->customer_phone ?? '',
            'purpose' => $visit->purpose_label,
            'source' => $row->source->label(),
            'date' => $visit->visited_on,
            'time' => $visit->visited_time,
            'products' => implode(', ', $visit->products),
            'respondent' => $visit->attended_by ?? '',
            'follow_up' => $row->expected_follow_up_on?->format('j M Y') ?? '',
            'notes' => $row->notes ?? '',
        };
    }
}
