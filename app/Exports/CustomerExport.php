<?php

declare(strict_types=1);

namespace App\Exports;

use App\Concerns\ExportsTextColumns;
use App\Data\CustomerRowData;
use App\Enums\CustomerSegment;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * @implements WithMapping<Customer>
 */
class CustomerExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping, WithTitle
{
    use ExportsTextColumns;

    /**
     * @param  Builder<Customer>  $query  Already filtered and authorised.
     * @param  string  $format  Decides whether the text columns need their CSV escape.
     */
    public function __construct(
        private Builder $query,
        private string $format = 'csv',
    ) {}

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
            'Segment',
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
            # Printed as its label, which `CustomerSegment::match` reads back:
            # this sheet is also the import template.
            $row->segment === null ? '' : CustomerSegment::readable($row->segment),
            $this->textCell($customer->phone, $this->format),
            $customer->email ?? '',
            $this->textCell($row->id_number, $this->format),
            $row->street_address ?? '',
            $row->area ?? '',
            $row->city ?? '',
            $row->state ?? '',
            $this->textCell($row->postal_code, $this->format),
            $row->country,
            $customer->visits_count,
            $customer->last_visit ?? '',
        ];
    }

    /**
     * Phone, national ID and postal code are forced to text: a spreadsheet
     * that reads them as quantities eats the leading zero.
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
