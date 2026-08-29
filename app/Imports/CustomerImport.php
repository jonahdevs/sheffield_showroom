<?php

declare(strict_types=1);

namespace App\Imports;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Customers\LegacyExtract;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * A spreadsheet of customers, written into the list.
 *
 * The file this reads is the file `CustomerExport` writes, so the ordinary way
 * to correct three hundred records is to download the list, fix a column of it
 * in a spreadsheet, and send it back.
 *
 * A row the rules refuse is skipped and reported rather than taking the rest of
 * the file down with it. Somebody who mistyped one telephone in four hundred
 * rows should get three hundred and ninety-nine customers and a count of one,
 * not an error page and nothing imported.
 *
 * Rows are matched on the telephone through `Customer::matchingPhone`, which
 * compares the subscriber tail rather than the string: sending the same file
 * twice updates the same people instead of filing every one of them again under
 * a slightly different spelling of their number. The `ID` column the export
 * writes is ignored for the same reason - a row that has been through a
 * spreadsheet has no claim to be the record it was copied from.
 *
 * Every cell is read as text. Left to itself the reader decides `0722000111`
 * is the quantity 722000111 and `00100` is the number 100, and the leading
 * zero that makes one dialable and the other a postcode is gone before any of
 * this sees the row. Binding to strings is the only place that can be stopped:
 * nothing downstream can tell a number that lost a zero from one that never
 * had one.
 */
class CustomerImport extends StringValueBinder implements OnEachRow, SkipsEmptyRows, SkipsOnFailure, WithChunkReading, WithCustomValueBinder, WithHeadingRow, WithValidation
{
    use SkipsFailures;

    /**
     * What is written even when the cell behind it is empty.
     *
     * Everywhere else a blank cell says nothing about the record rather than
     * saying the record should be blanked - a file listing only names and new
     * telephones must not wipe every address on the way through. These four
     * are the exception: three of them are required, and the company name
     * follows the type, so correcting a row from company to individual has to
     * be able to clear it.
     *
     * @var array<int, string>
     */
    private const ALWAYS_WRITTEN = ['type', 'name', 'phone', 'company_name'];

    private int $created = 0;

    private int $updated = 0;

    /**
     * @param  int|null  $actorId  Who is importing, stamped on the customers
     *                             this file adds. Null where nobody is signed
     *                             in, which only happens in a console run.
     */
    public function __construct(
        private readonly LegacyExtract $extract,
        private readonly ?int $actorId = null,
    ) {}

    public function onRow(Row $row): void
    {
        $values = $row->toArray();
        $shaped = $this->shape($values);

        if ($shaped === null) {
            return;
        }

        if ($this->write($values, $shaped)) {
            $this->created++;

            return;
        }

        $this->updated++;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            /* Nine digits is the shortest thing that can be a subscriber
               number here, and it is the floor `matchingPhone` needs to have
               anything to match on at all. Anything shorter is punctuation
               somebody typed to get past a required field. */
            'phone' => ['required', 'string', 'max:60', 'regex:/^(?:\D*\d){9,}\D*$/'],

            /* Blank falls back to individual in the shaper; a value that is
               neither word is a typo, and letting it through would file a
               company as a person and drop its company name on the way. */
            'type' => ['nullable', Rule::enum(CustomerType::class)],

            'company' => ['nullable', 'string', 'max:255', 'required_if:type,company'],
            'industry' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function customValidationMessages(): array
    {
        return [
            'name.required' => 'Every row needs a name.',
            'phone.required' => 'Every row needs a telephone number.',
            'phone.regex' => 'That is not a telephone number.',
            'company.required_if' => 'A company row needs a company name.',
            'email.email' => 'That is not an email address.',
        ];
    }

    /**
     * The file's own headings, tidied into the keys the rules and the shaper
     * expect.
     *
     * `company_name` is accepted alongside `company` because it is the
     * database's name for the column, and somebody building a file by hand
     * will reach for that as readily as for the heading the export writes.
     *
     * The type is folded to lower case before it is checked. The export prints
     * it as "Individual" because that is what the screen shows, and a file this
     * application produced has to be one it can read back - refusing its own
     * capital letter would make the round trip a lie.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareForValidation(array $data, int $index): array
    {
        if (! array_key_exists('company', $data) && array_key_exists('company_name', $data)) {
            $data['company'] = $data['company_name'];
        }

        if (is_scalar($data['type'] ?? null)) {
            $type = mb_strtolower(trim((string) $data['type']));

            /* A blank cell is the file not saying, which the shaper reads as
               an individual. `nullable` only skips null, so an empty string
               has to become one or the enum rule would refuse the row. */
            $data['type'] = $type === '' ? null : $type;
        }

        return $data;
    }

    public function chunkSize(): int
    {
        return 200;
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function summary(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            /* By row rather than by failure: a row that broke three rules is
               one customer who did not land, not three. */
            'skipped' => $this->failures()->pluck('row')->unique()->count(),
        ];
    }

    /**
     * The row's values as a `customers` row.
     *
     * Shaped by `LegacyExtract` rather than by a second set of rules written
     * here. That class already decides what counts as a usable telephone, when
     * an email is worth keeping, and that the company columns belong to
     * companies only - and it decides those things for the migrated records
     * this list is mostly made of. Two answers to those questions would mean a
     * customer imported today differing from the identical customer imported
     * by the seed, which is the kind of drift nobody notices until the
     * duplicates turn up.
     *
     * Null where the shaper cannot use the row at all. Validation catches that
     * first, so this is a guard rather than a path.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function shape(array $values): ?array
    {
        return $this->extract->toSeedRow([
            'customer_type' => $values['type'] ?? null,
            /* The file gives one name where the old system gave two, so it
               goes in the first and the shaper's join leaves it whole. */
            'first_name' => $values['name'] ?? null,
            'company_name' => $values['company'] ?? null,
            'industry' => $values['industry'] ?? null,
            'phone_primary' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
            'id_number' => $values['id_number'] ?? null,
            'address_line1' => $values['street_address'] ?? null,
            'address_line2' => $values['area'] ?? null,
            'city' => $values['city'] ?? null,
            /* The export heads this column "County", which is what somebody
               in Nairobi calls it; the column behind it is `state`. Both
               spellings are read, or a file this application produced would
               lose the county on its way back in. */
            'state_province' => $values['state'] ?? $values['county'] ?? null,
            'postal_code' => $values['postal_code'] ?? null,
            'country' => $values['country'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values  The row as the file wrote it.
     * @param  array<string, mixed>  $shaped
     * @return bool Whether this row wrote a customer who was not on the list.
     */
    private function write(array $values, array $shaped): bool
    {
        $customer = Customer::query()
            ->matchingPhone((string) $shaped['phone'])
            ->first();

        $creating = $customer === null;
        $customer ??= new Customer;

        $customer->fill($this->attributes($values, $shaped, $creating));

        if ($creating) {
            $customer->created_by = $this->actorId;
        }

        $customer->save();

        return $creating;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $shaped
     * @return array<string, mixed>
     */
    private function attributes(array $values, array $shaped, bool $creating): array
    {
        /* The timestamps and the old system's key are the migration's business
           - they say when and under what reference a record was first written
           over there - and a file somebody uploaded has no standing to set
           any of them. */
        unset($shaped['created_at'], $shaped['updated_at'], $shaped['legacy_id']);

        if ($creating) {
            return $shaped;
        }

        $attributes = array_filter($shaped, fn (mixed $value): bool => $value !== null);

        foreach (self::ALWAYS_WRITTEN as $column) {
            $attributes[$column] = $shaped[$column];
        }

        /* The shaper defaults a blank country to Kenya, which is right for a
           new record and wrong for an existing one: a file that never
           mentioned the country would quietly move everybody home. */
        if (trim((string) ($values['country'] ?? '')) === '') {
            unset($attributes['country']);
        }

        return $attributes;
    }
}
