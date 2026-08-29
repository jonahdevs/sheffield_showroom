<?php

use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
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

/**
 * Somebody who runs the floor: every visit, not only their own, and the
 * customer records behind them - a correction typed on the visit form reaches
 * the customer only for whoever may edit customers.
 */
function visitManager(): User
{
    return visitStaff([
        Permission::VisitsViewAny,
        Permission::VisitsViewOwn,
        Permission::VisitsCreate,
        Permission::VisitsUpdate,
        Permission::VisitsDelete,
        Permission::CustomersUpdate,
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
    $customer = Customer::factory()->create();

    return [
        'customer_id' => $customer->id,
        /* The form shows a picked customer's own details back and posts them
           with the rest, so the payload carries them too. */
        'customer_type' => $customer->type->value,
        'customer_name' => $customer->name,
        'company_name' => $customer->company_name,
        'phone' => $customer->phone,
        'email' => $customer->email,
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
        'products' => [],
    ];
}

/**
 * Products as the form posts them: an id, how many, and how keen they were.
 *
 * @param  array<int, int>  $ids
 * @return array<int, array{id: int, quantity: int, interest_level: string}>
 */
function pickedProducts(
    array $ids,
    InterestLevel $level = InterestLevel::Medium,
    int $quantity = 1,
): array {
    return array_map(
        fn (int $id) => [
            'id' => $id,
            'quantity' => $quantity,
            'interest_level' => $level->value,
        ],
        $ids,
    );
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
/**
 * The four figures above the list. Each window is a floor on `visited_at`, so
 * a visit today is also counted in the week and the month - they narrow, they
 * do not partition.
 */
it('counts the log by today, this week and this month', function () {
    $manager = visitManager();

    /* Pinned to a Wednesday so "this week" has days either side of today
       inside it, and the month boundary is nowhere near. */
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00:00'));

    Visit::factory()->count(2)->create(['visited_at' => now()]);
    Visit::factory()->create(['visited_at' => now()->startOfWeek()->addHours(9)]);
    Visit::factory()->create(['visited_at' => now()->startOfMonth()->addHours(9)]);
    Visit::factory()->create(['visited_at' => now()->subMonths(3)]);

    $this->actingAs($manager)
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 5)
            ->where('stats.1.value', 2)
            ->where('stats.2.value', 3)
            ->where('stats.3.value', 4));
});

/**
 * Each window carries the same window before it, so the tile can say which way
 * the floor is going rather than only how busy it is.
 */
it('compares each window against the one before it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00:00'));

    /* Four today against two yesterday: up a hundred per cent. */
    Visit::factory()->count(4)->create(['visited_at' => now()]);
    Visit::factory()->count(2)->create(['visited_at' => now()->subDay()]);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.1.key', 'today')
            ->where('stats.1.value', 4)
            ->where('stats.1.previous', 2)
            ->where('stats.1.change', 100)
            /* A running total has nothing before it - every visit there has
               ever been is already in the figure. */
            ->where('stats.0.key', 'total')
            ->where('stats.0.change', null));
});

/** A salesperson's figures are their own log, not the floor's. */
it('counts only a salesperson\'s own visits in the figures', function () {
    $salesperson = visitSalesperson();

    Visit::factory()->count(2)->loggedBy($salesperson)->create(['visited_at' => now()]);
    Visit::factory()->count(5)->create(['visited_at' => now()]);

    $this->actingAs($salesperson)
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 2)
            ->where('stats.0.label', 'Visits you logged')
            ->where('stats.1.value', 2));
});

/**
 * The figures answer how busy the floor has been, which is a question about
 * the log rather than about whatever is typed in the search box. The pager
 * already says how many rows a filter matched.
 */
it('leaves the figures alone when the list is filtered', function () {
    $wanted = Customer::factory()->create(['name' => 'Achieng Odhiambo']);

    Visit::factory()->create(['customer_id' => $wanted->id, 'visited_at' => now()]);
    Visit::factory()->count(3)->create(['visited_at' => now()]);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['search' => 'Achieng']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 1)
            ->where('stats.0.value', 4));
});

/** A removed visit is not one the floor took. */
it('leaves a removed visit out of the figures', function () {
    Visit::factory()->create(['visited_at' => now()]);
    Visit::factory()->create(['visited_at' => now()])->delete();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 1)
            ->where('stats.1.value', 1));
});

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
        'products' => pickedProducts($products->pluck('id')->all()),
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
        'products' => pickedProducts([$product->id, $product->id]),
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
        'products' => pickedProducts([$after->id]),
    ]));

    expect($visit->fresh()->products->pluck('id')->all())->toBe([$after->id]);
});

it('records how keen they were on each product', function () {
    $wanted = Product::factory()->create();
    $glanced = Product::factory()->create();

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'products' => [
            ['id' => $wanted->id, 'quantity' => 20, 'interest_level' => InterestLevel::High->value],
            ['id' => $glanced->id, 'quantity' => 1, 'interest_level' => InterestLevel::Low->value],
        ],
    ]));

    $products = Visit::query()->sole()->products->keyBy('id');

    expect($products[$wanted->id]->pivot->interest_level)->toBe('high')
        ->and($products[$glanced->id]->pivot->interest_level)->toBe('low');
});

/**
 * A customer asks after twenty sheets rather than one, and a write-up that
 * records only which product they looked at cannot tell a roofing job from a
 * repair.
 */
it('records how many of each product they were after', function () {
    $sheets = Product::factory()->create();
    $nails = Product::factory()->create();

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'products' => [
            ['id' => $sheets->id, 'quantity' => 20, 'interest_level' => InterestLevel::High->value],
            ['id' => $nails->id, 'quantity' => 3, 'interest_level' => InterestLevel::Medium->value],
        ],
    ]));

    $products = Visit::query()->sole()->products->keyBy('id');

    expect($products[$sheets->id]->pivot->quantity)->toBe(20)
        ->and($products[$nails->id]->pivot->quantity)->toBe(3);
});

it('refuses a product nobody wanted any of', function () {
    $product = Product::factory()->create();

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'products' => [
                ['id' => $product->id, 'quantity' => 0, 'interest_level' => InterestLevel::Medium->value],
            ],
        ]))
        ->assertSessionHasErrors('products.0.quantity');
});

/** Past a few thousand it is a typo, not an order the floor took. */
it('refuses a quantity that is a slipped keystroke', function () {
    $product = Product::factory()->create();

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'products' => [
                ['id' => $product->id, 'quantity' => 100000, 'interest_level' => InterestLevel::Medium->value],
            ],
        ]))
        ->assertSessionHasErrors('products.0.quantity');
});

it('refuses a product with no interest level against it', function () {
    $product = Product::factory()->create();

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'products' => [['id' => $product->id, 'quantity' => 1]],
        ]))
        ->assertSessionHasErrors('products.0.interest_level');
});

it('refuses an interest level that is not one of the three', function () {
    $product = Product::factory()->create();

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'products' => [
                ['id' => $product->id, 'quantity' => 1, 'interest_level' => 'blazing'],
            ],
        ]))
        ->assertSessionHasErrors('products.0.interest_level');
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

/**
 * A company customer is a person and a company, not a company instead of a
 * person. Whoever walked in is who the counter dealt with, and the visit form
 * records them alongside the business they came for.
 */
it('records both the person and the company for a company visit', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), newCustomerPayload([
        'customer_type' => CustomerType::Company->value,
        'customer_name' => 'Peter Mwangi',
        'company_name' => 'Mwangi Builders Ltd',
        'industry' => 'Construction',
    ]));

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->name)->toBe('Peter Mwangi')
        ->and($customer->company_name)->toBe('Mwangi Builders Ltd')
        ->and($customer->industry)->toBe('Construction')
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

it('refuses a typed-in customer of either kind with no name', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'customer_name' => null,
        ]))
        ->assertSessionHasErrors('customer_name');

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'customer_type' => CustomerType::Company->value,
            'customer_name' => null,
            'company_name' => 'Mwangi Builders Ltd',
        ]))
        ->assertSessionHasErrors('customer_name');
});

it('refuses a typed-in company with no company name', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), newCustomerPayload([
            'customer_type' => CustomerType::Company->value,
            'company_name' => null,
        ]))
        ->assertSessionHasErrors('company_name');
});

/**
 * The fields stay editable behind a picked customer, so what comes back is the
 * record as it now stands - a number corrected at the counter is corrected
 * where it was noticed.
 */
it('saves a correction typed over a picked customer back to their record', function () {
    $existing = Customer::factory()->create([
        'type' => CustomerType::Individual,
        'name' => 'Achieng Odhiambo',
        'phone' => '0722 000 111',
    ]);

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'customer_id' => $existing->id,
        'customer_type' => CustomerType::Individual->value,
        'customer_name' => 'Achieng Odhiambo-Kamau',
        'phone' => '0799 999 999',
    ]));

    $existing->refresh();

    expect($existing->name)->toBe('Achieng Odhiambo-Kamau')
        ->and($existing->phone)->toBe('0799 999 999')
        ->and(Visit::query()->sole()->customer_id)->toBe($existing->id);
});

/**
 * Shown the details read-only, whoever may not edit customers has no edit to
 * send - and one that arrives anyway is not one the form offered them.
 */
it('leaves a picked customer alone for somebody who may not edit customers', function () {
    $existing = Customer::factory()->create([
        'type' => CustomerType::Individual,
        'name' => 'Achieng Odhiambo',
        'phone' => '0722 000 111',
    ]);

    $this->actingAs(visitSalesperson())->post(route('admin.visits.store'), visitPayload([
        'customer_id' => $existing->id,
        'customer_type' => CustomerType::Individual->value,
        'customer_name' => 'Somebody Else',
        'phone' => '0799 999 999',
    ]));

    $existing->refresh();

    expect($existing->name)->toBe('Achieng Odhiambo')
        ->and($existing->phone)->toBe('0722 000 111')
        ->and(Visit::query()->sole()->customer_id)->toBe($existing->id);
});

/**
 * The business half is only on screen for a company, so an individual's visit
 * write-up never showed the field and has no business clearing it. The
 * employer stays as it was entered under Customers.
 */
it('leaves the company an individual is recorded against alone', function () {
    $existing = Customer::factory()->create([
        'type' => CustomerType::Individual,
        'name' => 'Achieng Odhiambo',
        'company_name' => 'Mwangi Builders Ltd',
        'phone' => '0722 000 111',
    ]);

    $this->actingAs(visitManager())->post(route('admin.visits.store'), visitPayload([
        'customer_id' => $existing->id,
        'customer_type' => CustomerType::Individual->value,
        'customer_name' => 'Achieng Odhiambo',
        'company_name' => null,
        'phone' => '0722 000 111',
    ]));

    expect($existing->fresh()->company_name)->toBe('Mwangi Builders Ltd');
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
