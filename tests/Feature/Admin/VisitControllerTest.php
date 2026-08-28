<?php

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function visitStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'visits-'.fake()->unique()->word(),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/** Somebody who runs the floor: every visit, not only their own. */
function visitManager(): User
{
    return visitStaff([
        Permission::VisitsViewAny,
        Permission::VisitsViewOwn,
        Permission::VisitsCreate,
        Permission::VisitsUpdate,
        Permission::VisitsDelete,
    ]);
}

/** A salesperson: their own visits, and no reach past them. */
function visitSalesperson(): User
{
    return visitStaff([
        Permission::VisitsViewOwn,
        Permission::VisitsCreate,
        Permission::VisitsUpdate,
        Permission::VisitsDelete,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function visitPayload(array $overrides = []): array
{
    return [
        'customer_id' => Customer::factory()->create()->id,
        ...visitFields(),
        ...$overrides,
    ];
}

/**
 * The other half of the form: a customer nobody has met before, typed in
 * rather than picked off the list. No `customer_id`, and - unlike
 * `visitPayload` - no customer row created behind it either.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newCustomerPayload(array $overrides = []): array
{
    return [
        'customer_type' => CustomerType::Individual->value,
        'customer_name' => 'Achieng Odhiambo',
        'phone' => '0722 000 111',
        'email' => 'achieng@example.com',
        ...visitFields(),
        ...$overrides,
    ];
}

/**
 * Everything on the form that is about the visit rather than the customer.
 *
 * @return array<string, mixed>
 */
function visitFields(): array
{
    return [
        'respondent' => 'Achieng Odhiambo',
        'visited_on' => now()->subDay()->format('Y-m-d'),
        'visited_time' => '14:30',
        'purpose' => VisitPurpose::Quotation->value,
        'source' => CustomerSource::Referral->value,
        'duration_minutes' => 45,
        'notes' => 'Coming back on Friday.',
        'product_ids' => [],
    ];
}

it('refuses the log to somebody with neither half of the view split', function () {
    $user = visitStaff([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.visits.index'))
        ->assertForbidden();
});

it('lists every visit to somebody who may see the floor', function () {
    Visit::factory()->count(3)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/visits/Index')
            ->where('visits.total', 3)
            ->where('scoped_to_own', false));
});

/**
 * The whole point of the `view.own` half: a salesperson's list is their own
 * work, not the showroom's.
 */
it('shows a salesperson only the visits they logged', function () {
    $salesperson = visitSalesperson();

    Visit::factory()->count(2)->loggedBy($salesperson)->create();
    Visit::factory()->count(3)->create();

    $this->actingAs($salesperson)
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 2)
            ->where('scoped_to_own', true));
});

it('logs a visit against the person who recorded it', function () {
    $user = visitManager();
    $payload = visitPayload();

    $this->actingAs($user)
        ->post(route('admin.visits.store'), $payload)
        ->assertRedirect(route('admin.visits.index'));

    $visit = Visit::query()->sole();

    expect($visit->customer_id)->toBe($payload['customer_id'])
        ->and($visit->created_by)->toBe($user->id)
        ->and($visit->purpose)->toBe(VisitPurpose::Quotation)
        ->and($visit->source)->toBe(CustomerSource::Referral)
        ->and($visit->duration_minutes)->toBe(45)
        ->and($visit->visited_at->format('Y-m-d H:i'))
        ->toBe($payload['visited_on'].' 14:30');
});

it('attaches the products the customer was shown', function () {
    $products = Product::factory()->count(3)->create();

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'product_ids' => $products->pluck('id')->all(),
    ]));

    expect(Visit::query()->sole()->products)->toHaveCount(3);
});

/**
 * The pivot is unique on the pair, so a repeated id would fail on the way in
 * rather than being quietly stored twice.
 */
it('records a product shown twice on one visit only once', function () {
    $product = Product::factory()->create();

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'product_ids' => [$product->id, $product->id],
    ]));

    expect(Visit::query()->sole()->products)->toHaveCount(1);
});

it('replaces the products rather than adding to them on an edit', function () {
    $user = visitManager();
    $before = Product::factory()->count(2)->create();
    $after = Product::factory()->create();

    $visit = Visit::factory()->loggedBy($user)->create();
    $visit->products()->sync($before->pluck('id')->all());

    $this->actingAs($user)->patch(route('admin.visits.update', $visit), visitPayload([
        'customer_id' => $visit->customer_id,
        'product_ids' => [$after->id],
    ]));

    expect($visit->fresh()->products->pluck('id')->all())->toBe([$after->id]);
});

it('refuses a visit dated in the future', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'visited_on' => now()->addDay()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('visited_on');

    expect(Visit::query()->count())->toBe(0);
});

/**
 * Today plus a later hour passes both field rules on its own and is still a
 * visit that has not happened.
 */
it('pulls a visit logged later today back to now', function () {
    $this->travelTo(now()->setTime(10, 0));

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'visited_on' => now()->format('Y-m-d'),
        'visited_time' => '23:59',
    ]));

    expect(Visit::query()->sole()->visited_at->isFuture())->toBeFalse();
});

it('refuses a customer who is not on file', function () {
    $customer = Customer::factory()->create();
    $customer->delete();

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload(['customer_id' => $customer->id]))
        ->assertSessionHasErrors('customer_id');
});

it('keeps a duration that was left blank as nothing rather than zero', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'duration_minutes' => null,
    ]));

    expect(Visit::query()->sole()->duration_minutes)->toBeNull();
});

it('refuses a duration longer than a working day', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload(['duration_minutes' => 5000]))
        ->assertSessionHasErrors('duration_minutes');
});

it('lets a salesperson correct the visit they logged', function () {
    $salesperson = visitSalesperson();
    $visit = Visit::factory()->loggedBy($salesperson)->create();

    $this->actingAs($salesperson)
        ->get(route('admin.visits.edit', $visit))
        ->assertOk();
});

/**
 * `visits.update` is the permission to correct a write-up, not a way past the
 * boundary `view.own` draws.
 */
it('refuses a salesperson somebody else\'s visit', function () {
    $salesperson = visitSalesperson();
    $other = Visit::factory()->create();

    $this->actingAs($salesperson)
        ->get(route('admin.visits.edit', $other))
        ->assertForbidden();

    $this->actingAs($salesperson)
        ->patch(route('admin.visits.update', $other), visitPayload())
        ->assertForbidden();

    $this->actingAs($salesperson)
        ->delete(route('admin.visits.destroy', $other))
        ->assertForbidden();
});

it('lets a manager correct anybody\'s visit', function () {
    $visit = Visit::factory()->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.edit', $visit))
        ->assertOk();
});

it('soft deletes a visit rather than dropping it', function () {
    $visit = Visit::factory()->create();

    $this->actingAs(visitManager())
        ->delete(route('admin.visits.destroy', $visit))
        ->assertRedirect();

    expect(Visit::query()->count())->toBe(0)
        ->and(Visit::withTrashed()->count())->toBe(1);
});

it('finds a visit by the customer behind it', function () {
    $user = visitManager();

    $wanted = Customer::factory()->create(['name' => 'Achieng Odhiambo']);
    Visit::factory()->create(['customer_id' => $wanted->id]);
    Visit::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Someone Else'])->id]);

    $this->actingAs($user)
        ->get(route('admin.visits.index', ['search' => 'Achieng']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 1));
});

it('narrows the list to one purpose', function () {
    Visit::factory()->count(2)->for_purpose(VisitPurpose::Complaint)->create();
    Visit::factory()->count(3)->for_purpose(VisitPurpose::Order)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['purpose' => VisitPurpose::Complaint->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 2));
});

it('offers the form the customers and products it has to choose between', function () {
    Customer::factory()->count(2)->create();
    Product::factory()->count(3)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/visits/Form')
            ->where('visit', null)
            ->has('customers', 2)
            ->has('products', 3)
            ->has('purposes', count(VisitPurpose::cases()))
            ->has('sources', count(CustomerSource::cases())));
});

it('refuses to log a visit without visits.create', function () {
    $user = visitStaff([Permission::VisitsViewAny]);

    $this->actingAs($user)
        ->post(route('admin.visits.store'), visitPayload())
        ->assertForbidden();
});

/**
 * The time on the form is the time on the wall.
 *
 * Under UTC every visit typed in a Kenyan afternoon read as hours into the
 * future and was pulled back to the moment it was entered, so the salesperson
 * got a time they had not chosen. This is the guard on that.
 */
it('stores the time that was typed, to the minute', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'visited_on' => now()->subDay()->format('Y-m-d'),
        'visited_time' => '15:39',
    ]));

    expect(Visit::query()->sole()->visited_at->format('H:i'))->toBe('15:39');
});

it('keeps the application on the showroom floor\'s clock', function () {
    expect(config('app.timezone'))->toBe('Africa/Nairobi');
});

/**
 * Who took the visit is not always who typed it up: a manager writing up the
 * floor at the end of the day is the logger, not the respondent.
 */
it('records who attended the visit apart from who logged it', function () {
    $manager = visitManager();

    $this->actingAs($manager)->post(route('admin.visits.store'), visitPayload([
        'respondent' => 'Brian Kimani',
    ]));

    $visit = Visit::query()->sole();

    expect($visit->respondent)->toBe('Brian Kimani')
        ->and($visit->created_by)->toBe($manager->id);
});

it('refuses a visit with nobody against it', function () {
    $payload = visitPayload();
    unset($payload['respondent']);

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), $payload)
        ->assertSessionHasErrors('respondent');

    expect(Visit::query()->count())->toBe(0);
});

/**
 * The list names who took the visit; a row from before the field existed
 * falls back to whoever logged it rather than showing a dash.
 */
it('falls back to the logger when no respondent was recorded', function () {
    $manager = visitManager();

    Visit::factory()->loggedBy($manager)->create(['respondent' => null]);

    $this->actingAs($manager)
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.data.0.attended_by', $manager->name));
});

// =============================================================================
// Finding or typing the customer
// =============================================================================

it('adds a customer nobody has met before along with the visit', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload())
        ->assertRedirect(route('admin.visits.index'));

    $customer = Customer::query()->sole();

    expect($customer->name)->toBe('Achieng Odhiambo')
        ->and($customer->phone)->toBe('0722 000 111')
        ->and($customer->email)->toBe('achieng@example.com')
        ->and(Visit::query()->sole()->customer_id)->toBe($customer->id);
});

/**
 * The whole reason the number is required: a walk-in who came last month must
 * not be filed a second time because nobody thought to search first.
 */
it('attaches the visit to an existing customer on a matching number', function () {
    $existing = Customer::factory()->create([
        'name' => 'Achieng Odhiambo',
        'phone' => '+254 722 000 111',
    ]);

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'phone' => '0722 000 111',
        ]));

    expect(Customer::query()->count())->toBe(1)
        ->and(Visit::query()->sole()->customer_id)->toBe($existing->id);
});

it('files a different number as a different customer', function () {
    Customer::factory()->create(['phone' => '0722 000 111']);

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'phone' => '0733 999 888',
        ]));

    expect(Customer::query()->count())->toBe(2);
});

it('records a company by its company name rather than a person', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), newCustomerPayload([
        'customer_type' => CustomerType::Company->value,
        'customer_name' => null,
        'company_name' => 'Mwangi Builders Ltd',
    ]));

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->company_name)->toBe('Mwangi Builders Ltd')
        ->and($customer->name)->toBeNull()
        ->and($customer->displayName())->toBe('Mwangi Builders Ltd');
});

it('refuses a typed-in customer with no phone number', function () {
    $payload = newCustomerPayload();
    unset($payload['phone']);

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), $payload)
        ->assertSessionHasErrors('phone');

    expect(Visit::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('refuses a typed-in individual with no name', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'customer_name' => null,
        ]))
        ->assertSessionHasErrors('customer_name');
});

it('refuses a typed-in company with no company name', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'customer_type' => CustomerType::Company->value,
            'customer_name' => null,
            'company_name' => null,
        ]))
        ->assertSessionHasErrors('company_name');
});

/**
 * Picked off the list, the record on file is what counts - the visit form is
 * not the place to quietly rewrite a customer.
 */
it('leaves a picked customer\'s details alone', function () {
    $existing = Customer::factory()->create([
        'name' => 'Achieng Odhiambo',
        'phone' => '0722 000 111',
    ]);

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'customer_id' => $existing->id,
        'customer_name' => 'Somebody Else',
        'phone' => '0799 999 999',
    ]));

    $existing->refresh();

    expect($existing->name)->toBe('Achieng Odhiambo')
        ->and($existing->phone)->toBe('0722 000 111')
        ->and(Visit::query()->sole()->customer_id)->toBe($existing->id);
});

// =============================================================================
// Follow-up
// =============================================================================

it('records an expected follow-up date', function () {
    $follow = now()->addWeek()->format('Y-m-d');

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'expected_follow_up_on' => $follow,
    ]));

    expect(Visit::query()->sole()->expected_follow_up_on->format('Y-m-d'))->toBe($follow);
});

it('refuses a follow-up dated before the visit', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'visited_on' => now()->subDay()->format('Y-m-d'),
            'expected_follow_up_on' => now()->subWeek()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('expected_follow_up_on');
});

it('keeps a visit with no follow-up planned', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'expected_follow_up_on' => null,
    ]));

    expect(Visit::query()->sole()->expected_follow_up_on)->toBeNull();
});
