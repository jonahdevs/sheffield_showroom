<?php

use App\Services\Customers\LegacyExtract;
use App\Services\Visits\LegacyVisitLog;

/**
 * The rest of the row is a customer the import will accept, so a test about a
 * note is never really a test about a missing telephone number.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function loggedRow(array $overrides = []): array
{
    return [
        'id' => 6,
        'customer_type' => 'individual',
        'first_name' => 'Samuel',
        'last_name' => 'Kamau',
        'phone_primary' => '0716552760',
        'notes' => 'Showroom Visit',
        'created_at' => '2026-02-25 11:26:47',
        'updated_at' => '2026-02-25 11:26:47',
        ...$overrides,
    ];
}

/**
 * The extract as phpMyAdmin writes it - the rows live in the third block.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function loggedExtractJson(array $rows): string
{
    return (string) json_encode([
        ['type' => 'header', 'version' => '5.2.1'],
        ['type' => 'database', 'name' => 'sheffieldafrica_showroom'],
        ['type' => 'table', 'name' => 'customers', 'data' => $rows],
    ]);
}

function visitLog(): LegacyVisitLog
{
    return new LegacyVisitLog(new LegacyExtract);
}

# The whole department table, written out: a change to the mapping in
# `LegacyVisitLog` has to be a change to this dataset too.
it('reads the department a visitor came to see as the nearest purpose', function (string $note, string $purpose) {
    expect(visitLog()->purposeFor($note)->value)->toBe($purpose);
})->with([
    'showroom' => ['Showroom Visit', 'product_viewing'],
    'showroom, spelled out' => ['Showroom visit- pre rinse shower', 'product_viewing'],
    'cold room' => ['Cold room- Coldroom solutions', 'product_viewing'],
    'coldroom' => ['Coldroom- Coldroom panels', 'product_viewing'],
    'rational' => ['Rational- 6 tray demonstration', 'product_viewing'],
    'laundry' => ['Laundry- Calender ironer', 'product_viewing'],

    'accounts' => ["Accounts\nDelivery of invoices\nRachael", 'collection'],
    'cheque collection' => ['Cheque collection-Accounts', 'collection'],
    'collection of cheque' => ["Collection of cheque\nAccounts", 'collection'],
    'logistics' => ['Logistics-Collection of meat mincer', 'collection'],
    'collection of equipment' => ["Collection of equipment\nLogistics", 'collection'],

    'service' => ['Service- fryer repair', 'after_sales'],
    'service of equipment' => ['Service of equipment', 'after_sales'],
    'installation' => ["Installation\nDucting", 'after_sales'],

    'purchasing' => ["Purchasing\nDelivery of samples", 'order'],
    'purchase' => ["Purchase\nCollection of export goods", 'order'],
    'sales' => ["Sales\nMeeting", 'order'],
    'horeca' => ["HORECA\nDelivery", 'order'],

    'hr' => ['HR- Delivery of documents', 'other'],
    'admin' => ['Admin- Delivery of toner', 'other'],
    'imports' => ['Imports- delivery of spare part', 'other'],
    'import stores' => ['Import stores- Delivery of spares', 'other'],
    'production' => ["Production\nInspection", 'other'],
    'marketing' => ['Marketing-Delivery of invitation for expo', 'other'],
    'security' => ['Security- Fire extinguishers', 'other'],
    'design' => ["Design\nMeeting", 'other'],
    'a bare meeting' => ["Meeting\npurchase", 'other'],
    'a bare delivery' => ["Delivery of equipment\nPauline", 'other'],
    'a department nobody recognises' => ['Wickerwork', 'other'],
]);

it('reads a showroom note that asks about something as a new enquiry', function () {
    expect(visitLog()->purposeFor("Showroom\nInquiry on coffee machines\nColins")->value)
        ->toBe('new_enquiry');
});

it('reads an enquiry with no department in front of it as a new enquiry', function () {
    expect(visitLog()->purposeFor("Individual\nInquiry on coffee machine & ice cube makers\nAnn")->value)
        ->toBe('new_enquiry');
});

it('leaves a purpose the department already settled alone', function (string $note, string $purpose) {
    expect(visitLog()->purposeFor($note)->value)->toBe($purpose);
})->with([
    'an enquiry at the workshop' => ['Service-inquiry on spare parts', 'after_sales'],
    'an enquiry at the purchasing desk' => ['Purchasing- Inquiry on branding.', 'order'],
    'an enquiry at HR' => ['HR- Inquiry on team building', 'other'],
]);

it('records somebody who came about a job as other, whichever desk took them', function (string $note) {
    expect(visitLog()->purposeFor($note)->value)->toBe('other');
})->with([
    'at the laundry desk' => ['Laundry- Interview Christine'],
    'at HR' => ['HR-Inquiry on internship vacancy.'],
]);

it('names the member of staff a note says took the visit', function (string $note, string $respondent) {
    expect(visitLog()->respondentIn($note))->toBe($respondent);
})->with([
    'on a line of their own' => ["Showroom\nInquiry on bakery solutions.\nAnn", 'Ann'],
    'with a surname' => ["Showroom-Inquiry on ovens.\nSom Nath", 'Som Nath'],
    'after the department' => ['Security- Maureen', 'Maureen'],
    'between the department and the errand' => ['Coldroom- Alphonse-Inquiry on coldroom solution', 'Alphonse'],
    'at the end of a single line' => ['Delivery of documents for imports department-Joan', 'Joan'],
    'with the errand written after them' => ["Accounts\nRachael\nCheque collection", 'Rachael'],
    'attended to by' => ["Service- bonesaw cutter repair\nAttended to by Sharon", 'Sharon'],
    'dealt with them' => ["Service of equipment\nGirraj dealt with them", 'Girraj'],
    'received by' => ["Admin delivery of toner\nReceived by Richard", 'Richard'],
    'met in a meeting' => ['HORECA- Meeting Joseph', 'Joseph'],
    'met about something' => ['Purchasing- Meeting Hezekiah on gromets', 'Hezekiah'],
    'behind a full stop' => ["Marketing- Meeting.\nQueenter.", 'Queenter'],
]);

it('leaves the respondent blank rather than reading an errand as a person', function (string $note) {
    expect(visitLog()->respondentIn($note))->toBeNull();
})->with([
    'a department' => ["Logistics\nCollection of samples\nLogistics"],
    'an errand' => ["Accounts\nRachael was out\nCheque collection"],
    'two words that are not a name' => ["Admin\nMeter reading\nAdmin"],
    'a job title' => ["Production\nInterview\nWelder"],
    'an office rather than a person' => ["Admin\nMeeting\nMD"],
    'two people at once' => ["Collection of equipment\nLogistics\nMaureen/Hezekiah"],
    'nobody at all' => ['Showroom- Inquiry on commercial microwaves'],
]);

it('reads one man written two ways as one man', function () {
    expect(visitLog()->respondentIn("Meeting\npurchase\nMr Hezekiah"))->toBe('Hezekiah');
});

it('keeps the note as it was written, apart from its line endings', function () {
    $row = visitLog()->toSeedRow(loggedRow([
        'notes' => "Service- bonesaw cutter repair\r\nAttended to by Sharon",
    ]));

    expect($row['notes'])->toBe("Service- bonesaw cutter repair\nAttended to by Sharon");
});

it('records when they came rather than when the import ran', function () {
    $row = visitLog()->toSeedRow(loggedRow(['created_at' => '2026-02-25 11:26:47']));

    expect($row)
        ->visited_at->toBe('2026-02-25 11:26:47')
        ->created_at->toBe('2026-02-25 11:26:47');
});

it('records every visit in the log as a walk-in', function () {
    expect(visitLog()->toSeedRow(loggedRow())['source'])->toBe('walk_in');
});

it('invents nothing the extract does not hold', function () {
    expect(visitLog()->toSeedRow(loggedRow()))
        ->expected_follow_up_on->toBeNull()
        ->created_by->toBeNull();
});

it('carries the id the row had in the old system so the customer can be found again', function () {
    expect(visitLog()->toSeedRow(loggedRow(['id' => 87]))['legacy_id'])->toBe(87);
});

it('reads no visit out of a row the front desk wrote nothing on', function (mixed $notes) {
    expect(visitLog()->toSeedRow(loggedRow(['notes' => $notes])))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ["  \r\n "],
    'absent' => [null],
]);

it('reads no visit out of a row with no time against it', function () {
    expect(visitLog()->toSeedRow(loggedRow(['created_at' => ''])))->toBeNull();
});

it('leaves out a note whose customer the book never imported', function () {
    $result = visitLog()->transform(loggedExtractJson([
        loggedRow(['id' => 6]),
        loggedRow(['id' => 10, 'phone_primary' => 'N/A', 'notes' => 'Showroom- Inquiry on blenders']),
    ]));

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['legacy_id'])->toBe(6)
        ->and($result['without_customer'])->toBe([10]);
});

it('counts the rows that record a customer and no visit', function () {
    $result = visitLog()->transform(loggedExtractJson([
        loggedRow(['id' => 1, 'notes' => '']),
        loggedRow(['id' => 6]),
    ]));

    expect($result['rows'])->toHaveCount(1)
        ->and($result['unlogged'])->toBe(1);
});
