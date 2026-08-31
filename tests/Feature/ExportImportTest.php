<?php

use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Exceptions\DocumentRenderingFailedException;
use App\Exports\CustomerExport;
use App\Exports\VisitExport;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use App\Services\Documents\TableDocumentService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function transferStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'transfer-'.fake()->unique()->word(),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * A file as it arrives off somebody's machine.
 *
 * @param  array<int, array<int, string>>  $rows  The heading row first.
 */
function csvUpload(array $rows, string $name = 'customers.csv'): UploadedFile
{
    $body = implode("\n", array_map(
        fn (array $row) => implode(',', array_map(
            fn (string $cell) => '"'.str_replace('"', '""', $cell).'"',
            $row,
        )),
        $rows,
    ));

    return UploadedFile::fake()->createWithContent($name, $body);
}

// =============================================================================
// Exports
// =============================================================================

it('exports only the customers the screen was filtered to', function () {
    Excel::fake();

    $company = Customer::factory()->create([
        'type' => CustomerType::Company,
        'company_name' => 'Mwangi Builders Ltd',
    ]);
    Customer::factory()->create(['type' => CustomerType::Individual]);

    $staff = transferStaff([Permission::CustomersViewAny, Permission::CustomersExport]);

    $this->actingAs($staff)
        ->get(route('admin.customers.export', ['type' => 'company']))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'customers-'.now()->toDateString().'.csv',
        fn (CustomerExport $export) => $export->query()->pluck('id')->all() === [$company->id],
    );
});

it('exports the customers matching the search rather than the whole list', function () {
    Excel::fake();

    $wanted = Customer::factory()->create(['name' => 'Achieng Odhiambo']);
    Customer::factory()->create(['name' => 'Peter Mwangi']);

    $staff = transferStaff([Permission::CustomersViewAny, Permission::CustomersExport]);

    $this->actingAs($staff)
        ->get(route('admin.customers.export', ['search' => 'Achieng']))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'customers-'.now()->toDateString().'.csv',
        fn (CustomerExport $export) => $export->query()->pluck('id')->all() === [$wanted->id],
    );
});

it('refuses a customer export without customers.export', function () {
    $staff = transferStaff([Permission::CustomersViewAny]);

    $this->actingAs($staff)
        ->get(route('admin.customers.export'))
        ->assertForbidden();
});

it('gives a salesperson only the visits they logged', function () {
    Excel::fake();

    $salesperson = transferStaff([Permission::VisitsViewOwn, Permission::VisitsExport]);
    $colleague = User::factory()->create();

    $customer = Customer::factory()->create();

    $own = Visit::factory()->for($customer)->create(['created_by' => $salesperson->id]);
    Visit::factory()->for($customer)->create(['created_by' => $colleague->id]);

    $this->actingAs($salesperson)
        ->get(route('admin.visits.export'))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'visits-'.now()->toDateString().'.csv',
        fn (VisitExport $export) => $export->query()->pluck('id')->all() === [$own->id],
    );
});

it('gives a manager every visit on the floor', function () {
    Excel::fake();

    $manager = transferStaff([Permission::VisitsViewAny, Permission::VisitsExport]);
    $colleague = User::factory()->create();

    $customer = Customer::factory()->create();

    Visit::factory()->for($customer)->create(['created_by' => $manager->id]);
    Visit::factory()->for($customer)->create(['created_by' => $colleague->id]);

    $this->actingAs($manager)
        ->get(route('admin.visits.export'))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'visits-'.now()->toDateString().'.csv',
        fn (VisitExport $export) => $export->query()->count() === 2,
    );
});

/**
 * Somebody whose whole job is the front desk.
 *
 * The role's *name* is what decides the sheet, not its permissions - see
 * `VisitReport::forViewer()` - so this cannot go through `transferStaff()`,
 * which invents one.
 *
 * @param  array<int, Permission>  $permissions
 */
function receptionStaff(array $permissions = [Permission::VisitsViewAny, Permission::VisitsExport]): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => Role::RECEPTION,
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

it('hands reception the front desk sheet rather than the full log', function () {
    Excel::fake();

    Visit::factory()->for(Customer::factory())->create();

    $this->actingAs(receptionStaff())
        ->get(route('admin.visits.export'))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'reception-visits-'.now()->toDateString().'.csv',
        fn (VisitExport $export) => $export->headings() === [
            'Visitor name',
            'Company',
            'Contact',
            'Nature of visit',
            'Respondent',
        ],
    );
});

/**
 * The notes column is the write-up of what was actually said on the floor. It
 * is the reason the full export exists and the one column that must not follow
 * the sheet out to the front desk.
 */
it('keeps the write-up off reception\'s sheet', function () {
    Excel::fake();

    $visit = Visit::factory()->for(Customer::factory())->create([
        'notes' => 'Haggled hard on the oven; go in at 12% next time.',
    ]);

    $this->actingAs(receptionStaff())
        ->get(route('admin.visits.export'))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'reception-visits-'.now()->toDateString().'.csv',
        function (VisitExport $export) use ($visit): bool {
            $printed = implode('|', array_map(strval(...), $export->map($visit)));

            return ! in_array('Notes', $export->headings(), true)
                && ! str_contains($printed, 'Haggled hard');
        },
    );
});

/**
 * A wider role must never be quietly narrowed by a second one. A manager who
 * also covers the desk keeps the log they had, or they lose the notes column
 * with nothing on the screen to say why.
 */
it('leaves the full log with a manager who also covers the front desk', function () {
    Excel::fake();

    $manager = transferStaff([Permission::VisitsViewAny, Permission::VisitsExport]);

    $reception = Role::query()->create([
        'name' => Role::RECEPTION,
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $manager->assignRole($reception);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Visit::factory()->for(Customer::factory())->create();

    $this->actingAs($manager)
        ->get(route('admin.visits.export'))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'visits-'.now()->toDateString().'.csv',
        fn (VisitExport $export) => in_array('Notes', $export->headings(), true),
    );
});

it('typesets the same rows the spreadsheet carries', function () {
    /* The paper format goes through a headless Chrome the CI box may not have,
       so the renderer is stood in for. What is asserted here is the wiring -
       that the export reaches the typesetter with the screen's title and the
       line that says which slice of the list it is - not that Chrome works. */
    $document = Mockery::mock(TableDocumentService::class);
    $document->shouldReceive('render')
        ->once()
        ->withArgs(fn (object $export, string $title, string $subtitle): bool => $export instanceof CustomerExport
            && $title === 'Customers'
            && str_contains($subtitle, 'Companies only'))
        ->andReturn('%PDF-1.4 pretend');

    app()->instance(TableDocumentService::class, $document);

    Customer::factory()->create(['type' => CustomerType::Company]);

    $staff = transferStaff([Permission::CustomersViewAny, Permission::CustomersExport]);

    $this->actingAs($staff)
        ->get(route('admin.customers.export', ['format' => 'pdf', 'type' => 'company']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('says so rather than throwing when the host cannot typeset', function () {
    $document = Mockery::mock(TableDocumentService::class);
    $document->shouldReceive('render')
        ->once()
        ->andThrow(new DocumentRenderingFailedException('Customers'));

    app()->instance(TableDocumentService::class, $document);

    $staff = transferStaff([Permission::CustomersViewAny, Permission::CustomersExport]);

    $this->actingAs($staff)
        ->get(route('admin.customers.export', ['format' => 'pdf']))
        ->assertRedirect();
});

it('refuses a visit export without visits.export', function () {
    $staff = transferStaff([Permission::VisitsViewAny]);

    $this->actingAs($staff)
        ->get(route('admin.visits.export'))
        ->assertForbidden();
});

// =============================================================================
// Import
// =============================================================================

it('adds the customers a file names', function () {
    $staff = transferStaff([
        Permission::CustomersViewAny,
        Permission::CustomersCreate,
        Permission::CustomersUpdate,
        Permission::CustomersImport,
    ]);

    $file = csvUpload([
        ['type', 'name', 'company', 'phone', 'email'],
        ['individual', 'Achieng Odhiambo', '', '0722 000 111', 'achieng@example.com'],
        ['company', 'Peter Mwangi', 'Mwangi Builders Ltd', '020 271 1000', ''],
    ]);

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), ['file' => $file])
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->count())->toBe(2);

    $company = Customer::query()->where('company_name', 'Mwangi Builders Ltd')->sole();

    expect($company->type)->toBe(CustomerType::Company)
        ->and($company->name)->toBe('Peter Mwangi')
        /* A file is shaped by `LegacyExtract` on the way in, the same as the
           legacy import, so a Kenyan number written the way it is dialled at
           home lands in the shape the form stores one. */
        ->and($company->phone)->toBe('+254202711000')
        ->and($company->created_by)->toBe($staff->id);
});

it('updates a customer already reachable on that number rather than filing them twice', function () {
    $staff = transferStaff([
        Permission::CustomersViewAny,
        Permission::CustomersCreate,
        Permission::CustomersUpdate,
        Permission::CustomersImport,
    ]);

    $existing = Customer::factory()->create([
        'type' => CustomerType::Individual,
        'name' => 'Achieng O.',
        'phone' => '0722 000 111',
        'city' => 'Nairobi',
    ]);

    /* The same telephone written the way somebody else would write it, which
       is exactly the case `matchingPhone` exists for. */
    $file = csvUpload([
        ['type', 'name', 'phone', 'email'],
        ['individual', 'Achieng Odhiambo', '+254722000111', 'achieng@example.com'],
    ]);

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), ['file' => $file])
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->count())->toBe(1);

    $existing->refresh();

    expect($existing->name)->toBe('Achieng Odhiambo')
        ->and($existing->email)->toBe('achieng@example.com')
        /* A column the file never mentioned is not a column the file asked to
           be emptied. */
        ->and($existing->city)->toBe('Nairobi');
});

it('skips a row the rules refuse and lets the rest of the file land', function () {
    $staff = transferStaff([
        Permission::CustomersViewAny,
        Permission::CustomersCreate,
        Permission::CustomersUpdate,
        Permission::CustomersImport,
    ]);

    $file = csvUpload([
        ['type', 'name', 'company', 'phone'],
        ['individual', 'Achieng Odhiambo', '', '0722 000 111'],
        /* No name and no telephone worth the word. */
        ['individual', '', '', 'N/A'],
        /* A company with nothing to call it. */
        ['company', 'Peter Mwangi', '', '020 271 1000'],
        ['individual', 'Wanjiru Kamau', '', '0733 444 555'],
    ]);

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), ['file' => $file])
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->pluck('name')->all())
        ->toBe(['Achieng Odhiambo', 'Wanjiru Kamau']);
});

it('reads its own export back without duplicating anybody', function () {
    /* The claim the whole shape rests on: download the list, correct a column
       of it, send it back. If the export's headings and the import's keys ever
       drift apart, every row comes back as a new customer and the list doubles
       - which is the sort of thing somebody discovers a week later. */
    $staff = transferStaff([
        Permission::CustomersViewAny,
        Permission::CustomersCreate,
        Permission::CustomersUpdate,
        Permission::CustomersImport,
    ]);

    Customer::factory()->create([
        'type' => CustomerType::Company,
        'name' => 'Peter Mwangi',
        'company_name' => 'Mwangi Builders Ltd',
        'phone' => '020 271 1000',
        'state' => 'Nairobi',
        'postal_code' => '00100',
    ]);
    Customer::factory()->create([
        'type' => CustomerType::Individual,
        'name' => 'Achieng Odhiambo',
        'phone' => '0722 000 111',
    ]);

    $csv = Excel::raw(
        new CustomerExport(
            Customer::query()
                ->withCount('visits')
                ->withMax('visits', 'visited_at')
                ->orderBy('id'),
        ),
        ExcelWriter::CSV,
    );

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', (string) $csv),
        ])
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->count())->toBe(2);

    $company = Customer::query()->where('name', 'Peter Mwangi')->sole();

    expect($company->type)->toBe(CustomerType::Company)
        ->and($company->company_name)->toBe('Mwangi Builders Ltd')
        ->and($company->phone)->toBe('+254202711000')
        /* The export heads this "County" and the leading zero on the postcode
           is only there because the column was written as text. */
        ->and($company->state)->toBe('Nairobi')
        ->and($company->postal_code)->toBe('00100');
});

it('refuses an import without customers.import', function () {
    $staff = transferStaff([
        Permission::CustomersViewAny,
        Permission::CustomersCreate,
        Permission::CustomersUpdate,
    ]);

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), [
            'file' => csvUpload([['name', 'phone'], ['Achieng Odhiambo', '0722 000 111']]),
        ])
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});

it('refuses an import from somebody who may not write customers by hand', function () {
    /* Importing is create and update at four hundred rows a time, so the
       permission to import is not on its own enough to do it. */
    $staff = transferStaff([Permission::CustomersViewAny, Permission::CustomersImport]);

    $this->actingAs($staff)
        ->post(route('admin.customers.import'), [
            'file' => csvUpload([['name', 'phone'], ['Achieng Odhiambo', '0722 000 111']]),
        ])
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});
