<?php

use App\Services\Customers\LegacyExtract;

/**
 * One row of the old system's export, with only the columns a test cares
 * about spelled out. Everything else is present and blank, the way most of
 * the real extract is.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extractRow(array $overrides = []): array
{
    return [
        'id' => 1,
        'customer_type' => 'individual',
        'first_name' => 'Wanjiru',
        'last_name' => 'Kamau',
        'date_of_birth' => '',
        'company_name' => '',
        'email' => '',
        'phone_primary' => '0722000111',
        'phone_secondary' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'state_province' => '',
        'postal_code' => '',
        'country' => '',
        'id_type' => '',
        'id_number' => '',
        'tin_number' => '',
        'occupation' => '',
        'company_position' => '',
        'industry' => '',
        'preferred_contact_method' => '',
        'notes' => '',
        'created_by' => 1,
        'created_at' => '2026-02-06 13:04:58',
        'updated_at' => '2026-03-01 09:00:00',
        ...$overrides,
    ];
}

/**
 * The extract as phpMyAdmin writes it: a header, the database, and the table
 * that actually holds the rows.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function extractJson(array $rows): string
{
    return (string) json_encode([
        ['type' => 'header', 'version' => '5.2.1'],
        ['type' => 'database', 'name' => 'sheffieldafrica_showroom'],
        ['type' => 'table', 'name' => 'customers', 'database' => 'sheffieldafrica_showroom', 'data' => $rows],
    ]);
}

it('joins the two name columns with a single space', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['first_name' => ' Wanjiru ', 'last_name' => ' Kamau ']));

    expect($row['name'])->toBe('Wanjiru Kamau');
});

it('drops a blank half of the name rather than leaving a stray space', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['first_name' => '', 'last_name' => 'Kamau']));

    expect($row['name'])->toBe('Kamau');
});

it('falls back to the company name when nobody is named', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow([
        'customer_type' => 'company',
        'first_name' => '',
        'last_name' => '',
        'company_name' => 'Tausi Insurance',
    ]));

    expect($row['name'])->toBe('Tausi Insurance')
        ->and($row['company_name'])->toBe('Tausi Insurance');
});

it('keeps the company name and industry for a company', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow([
        'customer_type' => 'company',
        'company_name' => 'Boramex',
        'industry' => 'Construction',
    ]));

    expect($row['type'])->toBe('company')
        ->and($row['company_name'])->toBe('Boramex')
        ->and($row['industry'])->toBe('Construction');
});

/**
 * The form in this application never shows either column for a person, so a
 * value left there by the old system could never be seen or corrected.
 */
it('nulls the company name and industry for an individual', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow([
        'customer_type' => 'individual',
        'company_name' => 'Jason',
        'industry' => 'Individual',
    ]));

    expect($row['type'])->toBe('individual')
        ->and($row['company_name'])->toBeNull()
        ->and($row['industry'])->toBeNull();
});

it('treats an unrecognised customer type as an individual', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['customer_type' => '']));

    expect($row['type'])->toBe('individual');
});

it('strips the leading apostrophe a spreadsheet left on a phone number', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['phone_primary' => "'0705745046"]));

    expect($row['phone'])->toBe('+254705745046');
});

/**
 * One shape for every number, the one `PhoneInput` writes: a plus, a country
 * code, and the subscriber number. A row that keeps its trunk zero opens that
 * box on the wrong country.
 */
it('writes a phone number the way the form stores one', function (string $phone, string $expected) {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['phone_primary' => $phone]));

    expect($row['phone'])->toBe($expected);
})->with([
    'Kenyan mobile' => ['0722000111', '+254722000111'],
    /* The newer Kenyan mobile range, seven rows of the extract. */
    'Kenyan mobile on the 01 range' => ['0110000111', '+254110000111'],
    'Kenyan landline' => ['0202000111', '+254202000111'],
    'spaced' => ['0722 000 111', '+254722000111'],
    'hyphenated' => ['0722-000-111', '+254722000111'],
    /* Typed without the trunk zero, which one row in the extract is. */
    'bare national' => ['704320865', '+254704320865'],
    /* Already says where it is from, so it is left saying it. */
    'Uganda' => ['+256775879264', '+256775879264'],
    'Uganda without the plus' => ['256772501996', '+256772501996'],
    'United Kingdom' => ['+447771894871', '+447771894871'],
    'Netherlands' => ['+31624570166', '+31624570166'],
    'already Kenyan' => ['+254722000111', '+254722000111'],
    /* `00` is the older way of writing the plus, so the country code that
       follows it is not given a second one. */
    'international prefix' => ['00254722000111', '+254722000111'],
]);

it('skips a row whose phone column is not a number', function (string $phone) {
    expect((new LegacyExtract)->toSeedRow(extractRow(['phone_primary' => $phone])))->toBeNull();
})->with([
    'not applicable' => 'N/A',
    'lower case not applicable' => 'n/a',
    'truncated' => 'N/',
    'hash' => '#',
    'semicolon' => ';',
    'slashes' => '//',
    'plusses' => '++',
    'two digits' => '00',
    'eight digits' => '07220001',
]);

/**
 * Nine digits is the floor, and one row of the extract sits on it - a `07`
 * number a digit short. It is kept rather than judged: the transform is
 * mechanical, and a short number somebody can see and correct is better than
 * a customer silently dropped.
 */
it('keeps a nine digit number', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['phone_primary' => '071308438']));

    expect($row['phone'])->toBe('+25471308438');
});

it('keeps an email address only when it is one', function (string $email, ?string $expected) {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['email' => $email]));

    expect($row['email'])->toBe($expected);
})->with([
    'an address' => ['asaitreatske@gmail.com', 'asaitreatske@gmail.com'],
    'padded' => ['  asaitreatske@gmail.com  ', 'asaitreatske@gmail.com'],
    'blank' => ['', null],
    'not applicable' => ['N/A', null],
    'a name' => ['Isabella Muthoni', null],
]);

it('maps the address columns onto ours', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow([
        'address_line1' => 'Kenyatta Avenue',
        'address_line2' => 'Westlands',
        'city' => 'Nairobi',
        'state_province' => 'Nairobi County',
        'postal_code' => '00100',
        'country' => 'Netherlands',
    ]));

    expect($row)
        ->street_address->toBe('Kenyatta Avenue')
        ->area->toBe('Westlands')
        ->city->toBe('Nairobi')
        ->state->toBe('Nairobi County')
        ->postal_code->toBe('00100')
        ->country->toBe('Netherlands');
});

it('falls back to Kenya when the extract names no country', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['country' => '']));

    expect($row['country'])->toBe('Kenya');
});

it('turns every blank column into a null', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['id_number' => '   ']));

    expect($row)
        ->id_number->toBeNull()
        ->street_address->toBeNull()
        ->area->toBeNull()
        ->city->toBeNull()
        ->state->toBeNull()
        ->postal_code->toBeNull();
});

it('keeps the timestamps the old system recorded', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow());

    expect($row['created_at'])->toBe('2026-02-06 13:04:58')
        ->and($row['updated_at'])->toBe('2026-03-01 09:00:00');
});

/**
 * The notes column is the front desk's visit log and is imported by
 * `LegacyVisitLog` into a visit of its own, so it must not also arrive here as
 * prose on the customer. The rest are columns this application dropped on
 * purpose, and an import must not put any of them back.
 */
it('carries over nothing but the columns the customers table has', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['notes' => 'Called twice, no answer.']));

    expect(array_keys($row))->toBe([
        'legacy_id',
        'type', 'name', 'company_name', 'industry', 'phone', 'email', 'id_number',
        'street_address', 'area', 'city', 'state', 'postal_code', 'country',
        'created_at', 'updated_at',
    ]);
});

it('reads the rows out of the table block and reports what it left out', function () {
    $result = (new LegacyExtract)->transform(extractJson([
        extractRow(['id' => 1, 'phone_primary' => '0722000111']),
        extractRow(['id' => 2, 'phone_primary' => 'N/A']),
        extractRow(['id' => 3, 'phone_primary' => '0733419619']),
    ]));

    expect($result['rows'])->toHaveCount(2)
        ->and($result['skipped'])->toBe([['id' => 2, 'phone' => 'N/A']]);
});

/**
 * Reported, not resolved. Some of these are one person filed twice and some
 * are a switchboard several customers gave, and this cannot tell them apart -
 * counted the way `Customer::matchingPhone` counts them, on the subscriber
 * tail, so `0722 000 111` and `+254722000111` are one number.
 */
it('counts the numbers held by more than one customer', function () {
    $result = (new LegacyExtract)->transform(extractJson([
        extractRow(['id' => 1, 'phone_primary' => '0722000111']),
        extractRow(['id' => 2, 'phone_primary' => '0722 000 111']),
        extractRow(['id' => 3, 'phone_primary' => '+254722000111']),
        extractRow(['id' => 4, 'phone_primary' => '0733419619']),
    ]));

    expect($result['duplicate_phones'])->toBe(['722000111' => 3]);
});

it('rejects an export with no table block in it', function () {
    (new LegacyExtract)->transform((string) json_encode([['type' => 'header']]));
})->throws(RuntimeException::class, 'no table block');

/**
 * The extract carries two records in one row - the customer and the note the
 * front desk wrote when they came in - and they are imported separately. The
 * old system's id is what puts them back together: telephone numbers are
 * shared between records, and the keys the customers table hands out depend on
 * what was in it beforehand.
 */
it('carries over the id the row had in the old system', function () {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['id' => '87']));

    expect($row['legacy_id'])->toBe(87);
});

it('carries no id over when the row has nothing usable as one', function (mixed $id) {
    $row = (new LegacyExtract)->toSeedRow(extractRow(['id' => $id]));

    expect($row['legacy_id'])->toBeNull();
})->with([
    'blank' => [''],
    'absent' => [null],
    'not a number' => ['n/a'],
]);
