<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\CustomerType;
use RuntimeException;

/**
 * Reshapes the customer extract from the old system into rows this
 * application's `customers` table can take.
 *
 * The extract is a phpMyAdmin dump: an array of blocks, of which exactly one
 * describes a table and carries the rows. It is kept in the repository
 * untouched as the record of what was handed over, so nothing here writes back
 * to it - the transform is one-way and repeatable, and re-running it is how a
 * mapping decision gets revisited rather than by hand-editing the result.
 *
 * The old system carried a wider record than this one does: a date of birth,
 * a second number, a TIN, an occupation, a preferred contact method. Those
 * columns were dropped from this application deliberately, so they are dropped
 * here too rather than quietly resurrected by an import.
 */
class LegacyExtract
{
    /**
     * The digits that identify a subscriber once a country code or trunk
     * prefix is off the front. Matches `Customer::matchingPhone`, which is
     * what decides two records are the same telephone once they are in the
     * database - the duplicate count reported here has to mean the same thing.
     */
    private const SUBSCRIBER_DIGITS = 9;

    /**
     * The transformed rows, what was left out, and where the numbers collide.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     skipped: list<array{id: mixed, phone: string}>,
     *     duplicate_phones: array<string, int>,
     * }
     */
    public function transform(string $json): array
    {
        $rows = [];
        $skipped = [];

        foreach ($this->extractedRows($json) as $source) {
            $seedRow = $this->toSeedRow($source);

            if ($seedRow === null) {
                $skipped[] = [
                    'id' => $source['id'] ?? null,
                    'phone' => trim((string) ($source['phone_primary'] ?? '')),
                ];

                continue;
            }

            $rows[] = $seedRow;
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'duplicate_phones' => $this->duplicatePhones($rows),
        ];
    }

    /**
     * One extract row as one `customers` row, or null when it cannot be one.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function toSeedRow(array $source): ?array
    {
        $phone = $this->phone($source['phone_primary'] ?? null);

        /* A record whose only contact detail is "N/A" cannot be phoned, cannot
           be matched against a returning visitor, and cannot be de-duplicated
           against the rest of the extract. It is a name and nothing else, so
           it is left out rather than imported as a row somebody has to chase
           the missing half of. */
        if ($phone === null) {
            return null;
        }

        $type = $this->type($source['customer_type'] ?? null, $source);
        $isCompany = $type === CustomerType::Company;

        return [
            /* The id the row had in the old system, carried over so a second
               import can find the customer this row became. The `notes`
               column is a visit log and is imported separately, and by then
               nothing else about the row identifies it: phone numbers are
               shared between records and the keys this table hands out depend
               on what was in it beforehand. */
            'legacy_id' => $this->legacyId($source['id'] ?? null),

            'type' => $type->value,
            'name' => $this->name($source),

            /* Company columns for companies only. One individual in the
               extract carries a company name and an industry, which the form
               in this application never shows for a person and would therefore
               never let them correct. */
            'company_name' => $isCompany ? $this->named($source['company_name'] ?? null) : null,
            'industry' => $isCompany ? $this->named($source['industry'] ?? null) : null,

            'phone' => $phone,
            'email' => $this->email($source['email'] ?? null),
            'id_number' => $this->text($source['id_number'] ?? null),
            'street_address' => $this->text($source['address_line1'] ?? null),
            'area' => $this->text($source['address_line2'] ?? null),
            'city' => $this->text($source['city'] ?? null),
            'state' => $this->text($source['state_province'] ?? null),
            'postal_code' => $this->text($source['postal_code'] ?? null),
            'country' => $this->text($source['country'] ?? null) ?? 'Kenya',

            /* When they were first written down in the old system, not when
               this import ran. Stamping today would make every customer look
               like a walk-in from the day of the migration and flatten the
               only history the extract carries. */
            'created_at' => $this->text($source['created_at'] ?? null),
            'updated_at' => $this->text($source['updated_at'] ?? null),
        ];
    }

    /**
     * The rows out of the phpMyAdmin wrapper.
     *
     * The export is three blocks - a header, the database, and the table -
     * and only the last of those holds anything. Found by its `type` rather
     * than by position, because a re-export with different options moves it.
     *
     * Public because the visit log is read out of the same export by
     * `LegacyVisitLog`, and two copies of "where the rows are" would drift
     * apart the first time the old system is re-exported with other options.
     *
     * @return list<array<string, mixed>>
     */
    public function extractedRows(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The extract is not JSON this can read.');
        }

        foreach ($decoded as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'table' && isset($block['data'])) {
                return array_values(array_filter($block['data'], is_array(...)));
            }
        }

        throw new RuntimeException('The extract holds no table block, so there are no rows to read.');
    }

    /**
     * What to call them.
     *
     * The two name columns are joined rather than concatenated blindly, so a
     * record holding only a surname does not arrive with a leading space.
     * Thirteen rows in the extract name nobody at all - they were filed under
     * the business - and those fall back to the company, because a customer
     * with no name is unfindable on the list that sorts by it.
     *
     * @param  array<string, mixed>  $source
     */
    private function name(array $source): ?string
    {
        $parts = array_filter([
            $this->text($source['first_name'] ?? null),
            $this->text($source['last_name'] ?? null),
        ]);

        return $parts === []
            ? $this->named($source['company_name'] ?? null)
            : implode(' ', $parts);
    }

    /**
     * Individual or company.
     *
     * Anything unrecognised is a person: an individual keeps the name the
     * extract gave, where treating the row as a company would null that name
     * in favour of a company it has not got.
     *
     * A row filed as a company but carrying no company name is read the same
     * way. Three of them hold `company_name` "N/A" and `industry` "INDIVIDUAL"
     * over a real person's name - whoever typed them into the old system said,
     * as plainly as its form allowed, that this was not a business. Taken at
     * face value they would appear on the list as "N/A", because a company is
     * named by its company; taken as what they are they appear by name.
     *
     * @param  array<string, mixed>  $source
     */
    private function type(mixed $value, array $source): CustomerType
    {
        $type = CustomerType::tryFrom(mb_strtolower(trim((string) $value)))
            ?? CustomerType::Individual;

        if ($type === CustomerType::Individual) {
            return $type;
        }

        return $this->named($source['company_name'] ?? null) === null
            ? CustomerType::Individual
            : $type;
    }

    /**
     * A name, or null when what is there stands in for not having one.
     *
     * The old system's fields were required, so somebody with nothing to put
     * in them typed something that meant nothing - and stored as it stands,
     * "N/A" is read off a customer list as though it were a business trading
     * under that name. `CatalogueSync` guards the SKU column the same way and
     * for the same reason.
     */
    private function named(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        return in_array(
            mb_strtolower($text),
            ['null', 'nil', 'none', 'n/a', 'n/', 'na', '-', '--', 'undefined'],
            strict: true,
        ) ? null : $text;
    }

    /**
     * The number as it was written, or null when it is not a number at all.
     *
     * The leading apostrophe is an Excel artefact - it is how a spreadsheet
     * keeps `0722...` from being read as the integer 722 - and it is not part
     * of anybody's telephone. Beyond that the number is left in the shape it
     * was given: this application stores what was typed and matches on a
     * stripped copy at query time, so rewriting a Kenyan `07...` into `+254`
     * would only lose the form the person on the printed record recognises.
     *
     * Nine digits is the shortest thing that can be a subscriber number here.
     * Below that the value is punctuation somebody typed to get past a
     * required field - `N/A`, `#`, `;`, `//`, `++`, `00`.
     */
    private function phone(mixed $value): ?string
    {
        $phone = trim(ltrim(trim((string) $value), "'"));

        return strlen($this->digits($phone)) >= self::SUBSCRIBER_DIGITS ? $phone : null;
    }

    /**
     * The email address, or null when what is there is not one.
     *
     * Validated rather than trusted: a column holding a note to self is worse
     * than an empty one, because the application will try to send to it.
     */
    private function email(mixed $value): ?string
    {
        $email = $this->text($value);

        if ($email === null) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * The row's key in the old system, or null if it has not got one.
     *
     * Every row in the export carries one and it is written as a string, the
     * way phpMyAdmin writes every column. Null rather than 0 for anything
     * else: 0 is a key, and two records sharing it would be joined to each
     * other by the visit import.
     */
    private function legacyId(mixed $value): ?int
    {
        $id = $this->text($value);

        return $id !== null && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * A trimmed string, or null where the extract left an empty one.
     *
     * An empty string in a nullable column is a third state nothing in this
     * application tests for: `whereNull` misses it and a form shows it as
     * filled in.
     */
    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * How many rows share a telephone with another row, by subscriber tail.
     *
     * Reported rather than resolved. Several of these are genuinely one person
     * filed twice, but some are a switchboard, a landlord's number, or a
     * business whose staff all give the office line, and merging those on the
     * strength of a shared number would lose customers. No unique constraint
     * for the same reason - this is a figure for somebody to look at.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function duplicatePhones(array $rows): array
    {
        $tails = [];

        foreach ($rows as $row) {
            $tail = $this->subscriberTail((string) $row['phone']);
            $tails[$tail] = ($tails[$tail] ?? 0) + 1;
        }

        $duplicates = array_filter($tails, fn (int $count): bool => $count > 1);
        arsort($duplicates);

        return $duplicates;
    }

    private function subscriberTail(string $phone): string
    {
        $digits = $this->digits($phone);

        return strlen($digits) > self::SUBSCRIBER_DIGITS
            ? substr($digits, -self::SUBSCRIBER_DIGITS)
            : $digits;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
