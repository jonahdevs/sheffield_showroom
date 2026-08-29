<?php

declare(strict_types=1);

namespace App\Exports;

use App\Data\CustomerRowData;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The customer list, in both formats it is offered in.
 *
 * One definition of a row, so the CSV and the workbook cannot disagree about
 * what a customer record holds. Everything the screen shows is read back
 * through `CustomerRowData` rather than off the model a second time: the table
 * and the download are then the same answer to the same question, and a change
 * to one is a change to both.
 *
 * The columns the table has no room for - the industry, the ID number, the
 * address - are taken off the model here instead of being added to the row
 * DTO. That DTO ships on every page of the list, and widening it to serve a
 * download nobody asked for yet would make every request carry the cost.
 *
 * @implements WithMapping<Customer>
 */
class CustomerExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  Builder<Customer>  $query  Already filtered by the screen, and
     *                                    already carrying the visit aggregates
     *                                    `CustomerRowData` reads.
     */
    public function __construct(private Builder $query) {}

    public function title(): string
    {
        return 'Customers';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID',
            'Type',
            'Name',
            'Company',
            'Industry',
            'Phone',
            'Email',
            'ID number',
            'Street address',
            'Area',
            'City',
            'County',
            'Postal code',
            'Country',
            'Visits',
            'Last visit',
        ];
    }

    /**
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @param  Customer  $row
     * @return array<int, mixed>
     */
    public function map(mixed $row): array
    {
        $customer = CustomerRowData::fromModel($row);

        return [
            $customer->id,
            $customer->type_label,
            $customer->name ?? '',
            $customer->company_name ?? '',
            $row->industry ?? '',
            $customer->phone,
            $customer->email ?? '',
            $row->id_number ?? '',
            $row->street_address ?? '',
            $row->area ?? '',
            $row->city ?? '',
            $row->state ?? '',
            $row->postal_code ?? '',
            $row->country,
            $customer->visits_count,
            /* Blank rather than a zero date: a customer who has not been in
               yet has no last visit, and any date at all would read as one. */
            $customer->last_visit ?? '',
        ];
    }

    /**
     * Numbers as numbers, and the two columns that only look like numbers as
     * text.
     *
     * A phone, a national ID and a postal code all lose their leading zero the
     * moment a spreadsheet decides they are quantities - `0722 000 111`
     * becomes 722000111, `00100` becomes 100 - and this application
     * deliberately keeps a number in the shape it was given.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_TEXT,
            'H' => NumberFormat::FORMAT_TEXT,
            'M' => NumberFormat::FORMAT_TEXT,
            'O' => NumberFormat::FORMAT_NUMBER,
        ];
    }
}
