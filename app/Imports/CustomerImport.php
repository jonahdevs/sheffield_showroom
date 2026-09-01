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
 * Rows are matched on the telephone through `Customer::matchingPhone`, never
 * on the `ID` column the export writes, so sending the same file twice updates
 * the same people instead of filing them all again.
 *
 * `StringValueBinder` is load-bearing: left to itself the reader makes
 * `0722000111` the quantity 722000111 and `00100` the number 100, and nothing
 * downstream can tell a number that lost its leading zero from one that never
 * had one.
 */
class CustomerImport extends StringValueBinder implements OnEachRow, SkipsEmptyRows, SkipsOnFailure, WithChunkReading, WithCustomValueBinder, WithHeadingRow, WithValidation
{
    use SkipsFailures;

    /**
     * Written even when the cell is empty. Everywhere else a blank cell says
     * nothing rather than saying "blank this", or a file of names and new
     * telephones would wipe every address on the way through.
     *
     * @var array<int, string>
     */
    private const ALWAYS_WRITTEN = ['type', 'name', 'phone', 'company_name'];

    private int $created = 0;

    private int $updated = 0;

    /**
     * @param  int|null  $actorId  Null where nobody is signed in, which only
     *                             happens in a console run.
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

            # Nine digits is the floor `matchingPhone` needs to match on
            # anything at all.
            'phone' => ['required', 'string', 'max:60', 'regex:/^(?:\D*\d){9,}\D*$/'],

            'type' => ['nullable', Rule::enum(CustomerType::class)],

            'company' => ['nullable', 'string', 'max:255', 'required_if:type,company'],
            'segment' => ['nullable', 'string', 'max:255'],
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
     * The export prints "Individual" and heads a column "County", so both
     * spellings are read back here - a file this application produced has to
     * be one it can read.
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

            # `nullable` only skips null, so an empty string has to become
            # one or the enum rule refuses the row.
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
            'skipped' => $this->failures()->pluck('row')->unique()->count(),
        ];
    }

    /**
     * Shaped by `LegacyExtract`, never by a second set of rules here: two
     * answers would make a customer imported today differ from the identical
     * customer imported by the seed.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function shape(array $values): ?array
    {
        return $this->extract->toSeedRow([
            'customer_type' => $values['type'] ?? null,
            'first_name' => $values['name'] ?? null,
            'company_name' => $values['company'] ?? null,
            # The extract column `LegacyExtract` reads is still `industry`.
            'industry' => $values['segment'] ?? null,
            'phone_primary' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
            'id_number' => $values['id_number'] ?? null,
            'address_line1' => $values['street_address'] ?? null,
            'address_line2' => $values['area'] ?? null,
            'city' => $values['city'] ?? null,
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
        # A file somebody uploaded has no standing to set the timestamps or
        # the old system's key.
        unset($shaped['created_at'], $shaped['updated_at'], $shaped['legacy_id']);

        if ($creating) {
            return $shaped;
        }

        $attributes = array_filter($shaped, fn (mixed $value): bool => $value !== null);

        foreach (self::ALWAYS_WRITTEN as $column) {
            $attributes[$column] = $shaped[$column];
        }

        # The shaper defaults a blank country to Kenya, right for a new record
        # and wrong for an existing one - a file that never mentioned the
        # country would quietly move everybody home.
        if (trim((string) ($values['country'] ?? '')) === '') {
            unset($attributes['country']);
        }

        return $attributes;
    }
}
