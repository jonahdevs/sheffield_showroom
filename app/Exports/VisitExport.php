<?php

declare(strict_types=1);

namespace App\Exports;

use App\Data\VisitRowData;
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
 * The visit log, in both formats it is offered in.
 *
 * Mapped through `VisitRowData` for the same reason `CustomerExport` is: the
 * file has to be the list the viewer was looking at, and the only way to be
 * sure of that is for both to be built from one description of a row.
 *
 * The write-up is the exception. `VisitRowData` deliberately leaves the notes
 * on the record - a table row cannot hold a paragraph - but a download is
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
     */
    public function __construct(private Builder $query) {}

    public function title(): string
    {
        return 'Visits';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID',
            'Customer',
            'Company',
            'Type',
            'Phone',
            'Purpose',
            'Source',
            'Date',
            'Time',
            'Duration',
            'Products shown',
            'Respondent',
            'Follow-up due',
            'Notes',
        ];
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

        return [
            $visit->id,
            $visit->customer_name,
            $visit->customer_company ?? '',
            $visit->customer_type->label(),
            $visit->customer_phone ?? '',
            $visit->purpose_label,
            $row->source->label(),
            $visit->visited_on,
            $visit->visited_time,
            $visit->duration ?? '',
            implode(', ', $visit->products),
            $visit->attended_by ?? '',
            $row->expected_follow_up_on?->format('j M Y') ?? '',
            $row->notes ?? '',
        ];
    }

    /**
     * The phone as text, or a spreadsheet reads `0722 000 111` as a quantity
     * and eats the leading zero that makes it dialable.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
