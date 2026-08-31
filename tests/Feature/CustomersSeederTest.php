<?php

use App\Models\Customer;
use App\Models\Visit;
use Database\Seeders\CustomersSeeder;
use Database\Seeders\VisitsSeeder;

/**
 * The extract holds 453 rows and 29 of them have nothing in the phone column
 * that could be dialled. The rest is the book.
 */
const IMPORTED_CUSTOMERS = 424;

it('seeds every customer in the prepared file', function () {
    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->count())->toBe(IMPORTED_CUSTOMERS);
});

it('imports the whole prepared file and nothing else', function () {
    $prepared = json_decode(
        (string) file_get_contents(database_path('data/customers-seed.json')),
        true,
    );

    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->count())->toBe(count($prepared));
});

/**
 * The book replaces what was there. Everything in the table before the import
 * was scaffolding standing in until the real customers arrived.
 */
it('clears the customers that were there before', function () {
    $placeholder = Customer::factory()->create(['name' => 'Placeholder Person']);

    $this->seed(CustomersSeeder::class);

    $this->assertModelMissing($placeholder);
    expect(Customer::query()->count())->toBe(IMPORTED_CUSTOMERS);
});

it('clears a customer that was already soft deleted', function () {
    $removed = Customer::factory()->trashed()->create();

    $this->seed(CustomersSeeder::class);

    expect(Customer::withTrashed()->whereKey($removed->id)->exists())->toBeFalse();
});

/**
 * `visits.customer_id` restricts on delete so that history cannot be cleared
 * out from under a visit. The seeder respects that rather than working around
 * it: the customer stays, the visit stays, and the imported book lands beside
 * them.
 */
it('keeps a customer a visit is already logged against', function () {
    $visit = Visit::factory()->create();

    $this->seed(CustomersSeeder::class);

    $this->assertModelExists($visit);
    $this->assertModelExists($visit->customer);
    expect(Customer::query()->count())->toBe(IMPORTED_CUSTOMERS + 1);
});

it('keeps the dates the old system recorded rather than stamping today', function () {
    $this->seed(CustomersSeeder::class);

    $earliest = Customer::query()->min('created_at');

    expect($earliest)->toBe('2026-02-06 13:04:58');
});

it('imports each customer once when run twice', function () {
    $this->seed(CustomersSeeder::class);
    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->count())->toBe(IMPORTED_CUSTOMERS);
});

/**
 * Spot checks on the two shapes the extract carries, so a mapping rule that
 * silently changes shows up here and not on the showroom floor.
 */
it('imports a company with its company columns filled in', function () {
    $this->seed(CustomersSeeder::class);

    $this->assertDatabaseHas('customers', [
        'type' => 'company',
        'name' => 'ISABELLA MUTHONI',
        'company_name' => 'ASAI TREATS',
        'industry' => 'BAKERIES',
        /* Written `0746211877` in the extract, stored the way the form
           stores one - see `LegacyExtract::phone`. */
        'phone' => '+254746211877',
        'email' => 'asaitreatske@gmail.com',
        'city' => 'KAREN',
        'country' => 'Kenya',
        'notes' => null,
    ]);
});

it('imports an individual with no company columns', function () {
    $this->seed(CustomersSeeder::class);

    $individual = Customer::query()->where('type', 'individual')->get();

    expect($individual)->not->toBeEmpty()
        ->and($individual->pluck('company_name')->filter())->toBeEmpty()
        ->and($individual->pluck('industry')->filter())->toBeEmpty();
});

it('imports nobody without a phone number', function () {
    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->whereIn('phone', ['N/A', 'n/a', '#', ';', '//', '++'])->count())->toBe(0);
});

/**
 * Once the visit import has run, every customer in the book has a visit
 * pointing at it and none of them can be cleared out. Without the id the old
 * system gave each row, a second `db:seed` would spare all 424 and then insert
 * the same 424 on top of them.
 */
it('imports each customer once when the visits are already logged against them', function () {
    $this->seed(CustomersSeeder::class);
    $this->seed(VisitsSeeder::class);

    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->count())->toBe(IMPORTED_CUSTOMERS);
});

it('carries over the id each customer had in the old system', function () {
    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->whereNull('legacy_id')->count())->toBe(0)
        ->and(Customer::query()->distinct()->count('legacy_id'))->toBe(IMPORTED_CUSTOMERS);
});
