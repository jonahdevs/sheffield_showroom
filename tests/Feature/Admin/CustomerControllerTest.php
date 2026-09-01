<?php

use App\Enums\CustomerSegment;
use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Spatie\Permission\PermissionRegistrar;

/**
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
        'phone' => '0722 000 111',
        'email' => 'achieng@example.com',
        'id_number' => '12345678',
        'country' => 'Kenya',
        'state' => 'Nairobi',
        'city' => 'Nairobi',
        'street_address' => 'Plot 14, Enterprise Road',
        'area' => 'Industrial Area',
        'postal_code' => '00100',
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
        # A company customer is still a person: whoever came in from it.
        'name' => 'Peter Mwangi',
        'phone' => '020 271 1000',
        'email' => 'procurement@mwangi.co.ke',
        'id_number' => null,
        'company_name' => 'Mwangi Builders Ltd',
        'segment' => CustomerSegment::Corporate->value,
        'country' => 'Kenya',
        'state' => 'Nairobi',
        'city' => 'Nairobi',
        'street_address' => 'Baba Dogo Road',
        'area' => null,
        'postal_code' => '00100',
        'notes' => null,
    ], $overrides);
}

it('refuses the customers list without customers.view.any', function () {
    $user = staffWith([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertForbidden();
});

it('counts each customer\'s visits and dates the last one', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    $customer = Customer::factory()->create();

    Visit::factory()->create([
        'customer_id' => $customer->id,
        'visited_at' => now()->subMonth(),
    ]);
    Visit::factory()->create([
        'customer_id' => $customer->id,
        'visited_at' => now()->subDays(3),
    ]);

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customers.data.0.visits_count', 2)
            ->where('customers.data.0.last_visit', now()->subDays(3)->format('j M Y')));
});

it('leaves the last visit empty for a customer nobody has logged', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    Customer::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customers.data.0.visits_count', 0)
            ->where('customers.data.0.last_visit', null));
});

it('leaves a removed visit out of the count', function () {
    $user = staffWith([Permission::CustomersViewAny]);

    $customer = Customer::factory()->create();

    Visit::factory()->create(['customer_id' => $customer->id]);
    Visit::factory()->create(['customer_id' => $customer->id])->delete();

    $this->actingAs($user)
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customers.data.0.visits_count', 1));
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
        ->and($customer->id_number)->toBe('12345678')
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
        ->and($customer->name)->toBe('Peter Mwangi')
        ->and($customer->displayName())->toBe('Mwangi Builders Ltd');
});

it('requires a name from both types, and a company name from a company', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), individualPayload(['name' => null]))
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload(['name' => null]))
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload(['company_name' => null]))
        ->assertSessionHasErrors('company_name');

    expect(Customer::query()->count())->toBe(0);
});

it('clears the business fields when the type is individual', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)->post(route('admin.customers.store'), individualPayload([
        'company_name' => 'Stale Ltd',
        'segment' => 'Stale',
    ]));

    $customer = Customer::query()->sole();

    expect($customer->company_name)->toBeNull()
        ->and($customer->segment)->toBeNull()
        # Not the name - that one belongs to both types.
        ->and($customer->name)->toBe('Achieng Odhiambo');
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
        ->and($customer->name)->toBe('Peter Mwangi');
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
        ]))
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->sole()->phone)->toBe('+254722000111');
});

it('records the fuller address', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)->post(route('admin.customers.store'), individualPayload());

    $customer = Customer::query()->sole();

    expect($customer->street_address)->toBe('Plot 14, Enterprise Road')
        ->and($customer->area)->toBe('Industrial Area')
        ->and($customer->city)->toBe('Nairobi')
        ->and($customer->state)->toBe('Nairobi')
        ->and($customer->postal_code)->toBe('00100')
        ->and($customer->country)->toBe('Kenya')
        ->and($customer->addressLine())->toBe(
            'Plot 14, Enterprise Road, Industrial Area, Nairobi, Nairobi, 00100, Kenya'
        );
});

# =========================================================================
# Segment is free text, and the menu is only a suggestion
# =========================================================================

it('stores a segment picked from the menu and one typed under Other alike', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload([
            'segment' => CustomerSegment::CoffeeShops->value,
        ]))
        ->assertRedirect(route('admin.customers.index'));

    $this->actingAs($user)
        ->post(route('admin.customers.store'), companyPayload([
            'phone' => '0733 444 555',
            'segment' => 'Boat yards',
        ]))
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->orderBy('id')->pluck('segment')->all())
        ->toBe(['coffee_shops', 'Boat yards']);
});

it('reads a typed segment back as written and a known one by its label', function () {
    expect(CustomerSegment::readable('Boat yards'))->toBe('Boat yards')
        ->and(CustomerSegment::readable('coffee_shops'))->toBe('Coffee shops');
});

it('offers the segment menu to the form', function () {
    $user = staffWith([Permission::CustomersViewAny, Permission::CustomersCreate]);

    $this->actingAs($user)
        ->get(route('admin.customers.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('segments.0.value', CustomerSegment::Hotels->value)
            ->where('segments.8.label', 'Other'));
});
