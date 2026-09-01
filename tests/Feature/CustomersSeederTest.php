<?php

use App\Models\Customer;
use App\Models\Visit;
use Database\Seeders\CustomersSeeder;
use Database\Seeders\VisitsSeeder;

# 453 rows in the extract, 29 with nothing dialable in the phone column.
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

it('imports a company with its company columns filled in', function () {
    $this->seed(CustomersSeeder::class);

    $this->assertDatabaseHas('customers', [
        'type' => 'company',
        'name' => 'ISABELLA MUTHONI',
        'company_name' => 'ASAI TREATS',
        # `BAKERIES` in the extract, folded onto the case it names.
        'segment' => 'bakery',
        # Written `0746211877` in the extract - see `LegacyExtract::phone`.
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
        ->and($individual->pluck('segment')->filter())->toBeEmpty();
});

it('imports nobody without a phone number', function () {
    $this->seed(CustomersSeeder::class);

    expect(Customer::query()->whereIn('phone', ['N/A', 'n/a', '#', ';', '//', '++'])->count())->toBe(0);
});

# Once the visits are in, the FK spares every customer - so without
# `legacy_id` a second `db:seed` would insert all 424 a second time.
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
