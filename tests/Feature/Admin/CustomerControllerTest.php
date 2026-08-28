<?php

use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function staffWith(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'crew',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * @return array<string, mixed>
 */
function individualPayload(array $overrides = []): array
{
    return array_merge([
        'type' => CustomerType::Individual->value,
        'name' => 'Achieng Odhiambo',
        'date_of_birth' => '1985-04-12',
        'occupation' => 'Contractor',
        'phone' => '0722 000 111',
        'alternative_phone' => null,
        'email' => 'achieng@example.com',
        'address_line_1' => 'Plot 14, Enterprise Road',
        'address_line_2' => 'Industrial Area',
        'city' => 'Nairobi',
        'state' => 'Nairobi',
        'postal_code' => '00100',
        'country' => 'Kenya',
        'notes' => 'Came in for mabati pricing.',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return array_merge([
        'type' => CustomerType::Company->value,
        'company_name' => 'Mwangi Builders Ltd',
        'industry' => 'Construction',
        'contact_person' => 'Peter Mwangi',
        'contact_person_position' => 'Procurement Manager',
        'phone' => '020 271 1000',
        'alternative_phone' => '0733 444 555',
        'email' => 'procurement@mwangi.co.ke',
        'address_line_1' => 'Baba Dogo Road',
        'address_line_2' => null,
        'city' => 'Nairobi',
        'state' => 'Nairobi',
        'postal_code' => '00100',
        'country' => 'Kenya',
        'notes' => null,
    ], $overrides);
}

it('refuses the customers list without customers.view.any', function () {
    $user = staffWith([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertForbidden();
});

it('shows the customers list to somebody who may view it', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->create();
    Customer::factory()->company()->create();

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/customers/Index')
            ->where('customers.total', 2)
            ->where('counts.individual', 1)
            ->where('counts.company', 1));
});

it('records an individual', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload())
        ->assertRedirect(route('admin.customers.index'));

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Individual)
        ->and($customer->name)->toBe('Achieng Odhiambo')
        ->and($customer->occupation)->toBe('Contractor')
        ->and($customer->date_of_birth->format('Y-m-d'))->toBe('1985-04-12')
        ->and($customer->created_by)->toBe($user->id)
        ->and($customer->displayName())->toBe('Achieng Odhiambo');
});

it('records a company', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload())
        ->assertRedirect(route('admin.customers.index'));

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->company_name)->toBe('Mwangi Builders Ltd')
        ->and($customer->contact_person)->toBe('Peter Mwangi')
        ->and($customer->displayName())->toBe('Mwangi Builders Ltd')
        ->and($customer->subtitle())->toBe('Peter Mwangi - Procurement Manager');
});

it('requires a name from an individual and a company name from a company', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload(['name' => null]))
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload(['company_name' => null]))
        ->assertSessionHasErrors('company_name');

    expect(Customer::query()->count())->toBe(0);
});

/**
 * Both types share one table, so a company row carrying a date of birth is the
 * failure this guards against.
 */
it('drops the fields belonging to the other type', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)->post(route('admin.customers.store'), companyPayload([
        'name' => 'Left over from the individual form',
        'date_of_birth' => '1990-01-01',
        'occupation' => 'Stale',
    ]));

    $customer = Customer::query()->sole();

    expect($customer->name)->toBeNull()
        ->and($customer->date_of_birth)->toBeNull()
        ->and($customer->occupation)->toBeNull();
});

it('clears the company fields when the type is individual', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)->post(route('admin.customers.store'), individualPayload([
        'company_name' => 'Stale Ltd',
        'industry' => 'Stale',
        'contact_person' => 'Stale',
    ]));

    $customer = Customer::query()->sole();

    expect($customer->company_name)->toBeNull()
        ->and($customer->industry)->toBeNull()
        ->and($customer->contact_person)->toBeNull();
});

it('always requires a phone number', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload(['phone' => '']))
        ->assertSessionHasErrors('phone');
});

it('rejects a phone number carrying letters', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload(['phone' => 'call me']))
        ->assertSessionHasErrors('phone');
});

it('rejects an alternative number identical to the main one', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload([
            'phone' => '0722 000 111',
            'alternative_phone' => '0722 000 111',
        ]))
        ->assertSessionHasErrors('alternative_phone');
});

it('rejects a date of birth in the future', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload([
            'date_of_birth' => now()->addDay()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('date_of_birth');
});

it('refuses to create without customers.create', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload())
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});

it('updates a customer', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersUpdate]);

    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $this->actingAs($user)
        ->patch(route('admin.customers.update', $customer), individualPayload([
            'name' => 'New Name',
        ]))
        ->assertRedirect(route('admin.customers.index'));

    expect($customer->refresh()->name)->toBe('New Name');
});

it('converts an individual into a company on update', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersUpdate]);

    $customer = Customer::factory()->create();

    $this->actingAs($user)
        ->patch(route('admin.customers.update', $customer), companyPayload());

    $customer->refresh();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->company_name)->toBe('Mwangi Builders Ltd')
        ->and($customer->name)->toBeNull()
        ->and($customer->occupation)->toBeNull();
});

it('soft deletes a customer so their history survives', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersDelete]);

    $customer = Customer::factory()->create();

    $this->actingAs($user)
        ->delete(route('admin.customers.destroy', $customer));

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeFalse()
        ->and(Customer::withTrashed()->whereKey($customer->id)->exists())->toBeTrue();
});

it('refuses to delete without customers.delete', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersUpdate]);

    $customer = Customer::factory()->create();

    $this->actingAs($user)
        ->delete(route('admin.customers.destroy', $customer))
        ->assertForbidden();

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue();
});

it('filters the list by type', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->count(2)->create();
    Customer::factory()->company()->create();

    $this->actingAs($user)
        ->get(route('admin.customers.index', ['type' => 'company']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 1));
});

it('finds a customer by name, company, email or contact person', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->create(['name' => 'Achieng Odhiambo']);
    Customer::factory()->company()->create(['company_name' => 'Mwangi Builders Ltd']);

    foreach (['Achieng' => 1, 'Mwangi' => 1, 'nobody-by-that-name' => 0] as $term => $expected) {
        $this->actingAs($user)
            ->get(route('admin.customers.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customers.total', $expected));
    }
});

/**
 * People write a number down however they please, so the record keeps the
 * shape it was given and the search compares the subscriber tail.
 */
it('finds a customer by phone however it was spaced', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->create(['phone' => '0722 000 111']);

    foreach (['0722000111', '0722 000 111', '000111'] as $term) {
        $this->actingAs($user)
            ->get(route('admin.customers.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customers.total', 1));
    }
});

/**
 * The phone input writes `+254...`, and staff search by the `07...` they read
 * off a business card. Both have to find the same person, whichever way round
 * the record happens to be stored.
 */
it('finds a customer across the local and international forms', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->create(['phone' => '+254722000111']);
    Customer::factory()->create(['phone' => '0733 444 555']);

    $cases = [
        '0722000111' => 1,   // local form, stored international
        '+254722000111' => 1,
        '722000111' => 1,
        '+254733444555' => 1, // international form, stored local
        '0733444555' => 1,
    ];

    foreach ($cases as $term => $expected) {
        $this->actingAs($user)
            ->get(route('admin.customers.index', ['search' => (string) $term]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customers.total', $expected));
    }
});

it('accepts a number in the international form the phone input writes', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload([
            'phone' => '+254722000111',
            'alternative_phone' => '+254733444555',
        ]))
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->sole()->phone)->toBe('+254722000111');
});

it('records the fuller address', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)->post(route('admin.customers.store'), individualPayload());

    $customer = Customer::query()->sole();

    expect($customer->address_line_1)->toBe('Plot 14, Enterprise Road')
        ->and($customer->address_line_2)->toBe('Industrial Area')
        ->and($customer->city)->toBe('Nairobi')
        ->and($customer->state)->toBe('Nairobi')
        ->and($customer->postal_code)->toBe('00100')
        ->and($customer->country)->toBe('Kenya')
        ->and($customer->addressLine())->toBe(
            'Plot 14, Enterprise Road, Industrial Area, Nairobi, Nairobi, 00100, Kenya'
        );
});
