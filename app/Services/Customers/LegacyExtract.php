<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\CustomerSegment;
use App\Enums\CustomerType;
use RuntimeException;

class LegacyExtract
{
    # Must match `Customer::matchingPhone`, or the duplicate count reported here means
    # something other than what the application will treat as one telephone.
    private const SUBSCRIBER_DIGITS = 9;

    private const KENYA = '+254';

    /**
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
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    public function toSeedRow(array $source): ?array
    {
        $phone = $this->phone($source['phone_primary'] ?? null);

        if ($phone === null) {
            return null;
        }

        $type = $this->type($source['customer_type'] ?? null, $source);
        $isCompany = $type === CustomerType::Company;

        return [
            'legacy_id' => $this->legacyId($source['id'] ?? null),

            'type' => $type->value,
            'name' => $this->name($source),

            # The form never shows these for a person, so anything set here on an
            # individual is uncorrectable.
            'company_name' => $isCompany ? $this->named($source['company_name'] ?? null) : null,
            # The extract's column is still called `industry`: `customers.json` is
            # the record of what was handed over and is never rewritten.
            'segment' => $isCompany ? $this->segment($source['industry'] ?? null) : null,

            'phone' => $phone,
            'email' => $this->email($source['email'] ?? null),
            'id_number' => $this->text($source['id_number'] ?? null),
            'street_address' => $this->text($source['address_line1'] ?? null),
            'area' => $this->text($source['address_line2'] ?? null),
            'city' => $this->text($source['city'] ?? null),
            'state' => $this->text($source['state_province'] ?? null),
            'postal_code' => $this->text($source['postal_code'] ?? null),
            'country' => $this->text($source['country'] ?? null) ?? 'Kenya',

            'created_at' => $this->text($source['created_at'] ?? null),
            'updated_at' => $this->text($source['updated_at'] ?? null),
        ];
    }

    /**
     * The table block is found by its `type`, never by position - a re-export with
     * different options moves it.
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
     * A row filed as a company but carrying no company name is a person. Taken at face
     * value it appears on the list as "N/A", because a company is named by its company.
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
     * `customers.segment` is free text and `CustomerSegment` is only the menu the
     * form suggests, so a trade the list does not name is kept as it was typed.
     * Two things are not: the spellings `CustomerSegment::match` folds in, and the
     * customer *type*, which the old book's typists put in this column - a person
     * buying in their own name is not in the "Individual" trade.
     */
    private function segment(mixed $value): ?string
    {
        $text = $this->named($value);

        if ($text === null || CustomerType::tryFrom(mb_strtolower($text)) !== null) {
            return null;
        }

        return CustomerSegment::match($text)?->value ?? $text;
    }

    # The old system's fields were required, so "N/A" and its cousins mean "empty" -
    # stored as they stand they read off a customer list as a business of that name.
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

    # Exactly the shape `PhoneInput` writes: a `+`, a country code, no separators, no
    # trunk zero. The database never needed this - `Customer::matchingPhone` compares
    # stripped tails - but the form does: it splits a stored value on its dialling code,
    # and a value with no code to find opens on the wrong country with the zero still in.
    private function phone(mixed $value): ?string
    {
        $written = $this->unescaped($value);
        $digits = $this->digits($written);

        if (strlen($digits) < self::SUBSCRIBER_DIGITS) {
            return null;
        }

        if (str_starts_with($written, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($written, '0')) {
            $national = ltrim($digits, '0');

            # `00` is the older spelling of the plus: that number already carries a
            # country code and must not be given a second one.
            return str_starts_with($national, ltrim(self::KENYA, '+'))
                ? '+'.$national
                : self::KENYA.$national;
        }

        # Anything longer than a bare national number already carries a country code.
        return strlen($digits) === self::SUBSCRIBER_DIGITS
            ? self::KENYA.$digits
            : '+'.$digits;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->text($value);

        if ($email === null) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    # Null rather than 0: 0 is a key, and two records sharing it would be joined to
    # each other by the visit import.
    private function legacyId(mixed $value): ?int
    {
        $id = $this->text($value);

        return $id !== null && ctype_digit($id) ? (int) $id : null;
    }

    # An empty string in a nullable column is a third state nothing tests for:
    # `whereNull` misses it and a form shows it as filled in.
    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = $this->unescaped($value);

        return $text === '' ? null : $text;
    }

    # Excel's two text escapes, both of which reach us from our own CSV export
    # and from anything a person has edited in Excel. `="00100"` is what keeps a
    # leading zero through a double-click - see `ExportsTextColumns` - and a bare
    # apostrophe is what Excel leaves behind when somebody forces text by hand.
    # Stripped here so an exported sheet re-imports as what it displayed.
    private function unescaped(mixed $value): string
    {
        $written = trim((string) $value);

        if (preg_match('/^="(.*)"$/s', $written, $matches) === 1) {
            $written = $matches[1];
        }

        return trim(ltrim($written, "'"));
    }

    /**
     * Reported, never resolved: a shared number is as often a switchboard as a
     * duplicate. No unique constraint on the column, for the same reason.
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
