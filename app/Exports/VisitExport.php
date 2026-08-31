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
 * The visit log, in both formats it is offered in and in whichever column set
 * the reader's desk calls for.
 *
 * Mapped through `VisitRowData` for the same reason `CustomerExport` is: the
 * file has to be the list the viewer was looking at, and the only way to be
 * sure of that is for both to be built from one description of a row. A
 * `VisitReport` narrows which of those fields are printed and never which rows
 * are - the query arrives already filtered and already authorised.
 *
 * The write-up is the exception to `VisitRowData`. It deliberately leaves the
 * notes on the record - a table row cannot hold a paragraph - but a download is
 * exactly where somebody goes to read what was actually said on the floor, so
 * the prose, the source and the follow-up date come off the model here.
 *
 * @implements WithMapping<Visit>
 */
class VisitExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  Builder<Visit>  $query  Already filtered by the screen, and
     *                                 already narrowed to the visits this
     *                                 viewer is allowed to see.
     * @param  VisitReport  $report  Which columns to print. Defaults to the
     *                               full log, so a caller that has no opinion
     *                               gets what this export has always produced.
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
     * The phone as text, or a spreadsheet reads `0722 000 111` as a quantity
     * and eats the leading zero that makes it dialable.
     *
     * Which letter that is depends on the report - contact details sit fifth
     * in the full log and third on reception's sheet - so the column is found
     * by name rather than written down, and a report that does not print a
     * phone number formats nothing.
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
            /* 0 is A. Sixteen columns would need two letters, and no report
               here comes close. */
            chr(65 + (int) $position) => NumberFormat::FORMAT_TEXT,
        ];
    }

    /**
     * One field of one row, by the name a report asked for it under.
     *
     * The single place that says what each column means, so two reports naming
     * the same column cannot print different things into it.
     */
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
