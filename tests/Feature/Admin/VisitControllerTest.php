<?php

use App\Enums\CustomerSegment;
use App\Enums\CustomerSource;
use App\Enums\CustomerType;
use App\Enums\InterestLevel;
use App\Enums\Permission;
use App\Enums\VisitDepartment;
use App\Enums\VisitPurpose;
use App\Exports\VisitExport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

/**
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
        # The form posts a picked customer's own details back with the rest,
        # so the payload has to carry them too.
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
 * Unlike `visitPayload`, creates no customer row behind it and posts no
 * `customer_id`.
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
 * @return array<string, mixed>
 */
function visitFields(): array
{
    return [
        'respondent' => 'Achieng Odhiambo',
        'visited_on' => now()->subDay()->format('Y-m-d'),
        'visited_time' => '14:30',
        'purpose' => VisitPurpose::Quotation->value,
        'department' => VisitDepartment::Showroom->value,
        'source' => CustomerSource::Referral->value,
        'referred_by' => 'Mary Wanjiru',
        'notes' => 'Coming back on Friday.',
        'products' => [],
    ];
}

/**
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

it('measures the figures over the window the page is read under', function () {
    $regular = Customer::factory()->create();

    # Two visits from one customer and one from another, so the visit count
    # and the head count cannot be mistaken for each other.
    Visit::factory()->count(2)->create([
        'customer_id' => $regular->id,
        'visited_at' => '2026-02-14 11:00',
        'expected_follow_up_on' => null,
    ]);
    Visit::factory()->create([
        'visited_at' => '2026-02-20 09:00',
        'expected_follow_up_on' => '2026-03-02',
    ]);

    # Outside the window on both sides, one of them carrying a follow-up so
    # the third tile is proved windowed rather than counting the whole log.
    Visit::factory()->create([
        'visited_at' => '2026-01-20 09:00',
        'expected_follow_up_on' => '2026-02-01',
    ]);
    Visit::factory()->create([
        'visited_at' => '2026-03-05 09:00',
        'expected_follow_up_on' => null,
    ]);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.key', 'visits')
            ->where('stats.0.value', 3)
            ->where('stats.1.key', 'customers')
            ->where('stats.1.value', 2)
            ->where('stats.2.key', 'follow_ups')
            ->where('stats.2.value', 1)
            ->where('window_days', 28));
});

it('compares the window against the equally long one before it', function () {
    # A fortnight, 15 to 28 February, so the window before it is 1 to 14.
    Visit::factory()->count(4)->create(['visited_at' => '2026-02-20 11:00']);
    Visit::factory()->count(2)->create(['visited_at' => '2026-02-10 11:00']);

    # Earlier than the preceding window reaches: a comparison that quietly
    # counted everything before the window would come out at three.
    Visit::factory()->create(['visited_at' => '2026-01-31 11:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-15', 'to' => '2026-02-28']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window_days', 14)
            ->where('stats.0.value', 4)
            ->where('stats.0.previous', 2)
            ->where('stats.0.change', 100));
});

it('leaves the figures nothing to compare against where no window is set', function () {
    Visit::factory()->count(2)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window_days', null)
            ->where('stats.0.value', 2)
            ->where('stats.0.previous', 0)
            ->where('stats.0.change', null));
});

it('reads a named window off the query string', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00:00'));

    Visit::factory()->count(3)->create(['visited_at' => now()]);
    Visit::factory()->create(['visited_at' => now()->subDay()]);
    Visit::factory()->create(['visited_at' => now()->subMonths(3)]);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['range' => 'today']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 3)
            ->where('filters.range', 'today')
            ->where('filters.from', '2026-08-19')
            ->where('filters.to', '2026-08-19')
            ->where('window_days', 1)
            # Yesterday is the window before a window one day long.
            ->where('stats.0.value', 3)
            ->where('stats.0.previous', 1));
});

it('reads a window name it does not recognise as no window at all', function () {
    Visit::factory()->count(3)->create(['visited_at' => '2026-02-14 11:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['range' => 'since_the_beginning']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 3)
            ->where('filters.range', '')
            ->where('filters.from', '')
            ->where('date_label', 'All dates'));
});

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
        ->and($visit->purpose)->toBe(VisitPurpose::Quotation->value)
        ->and($visit->source)->toBe(CustomerSource::Referral->value)
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

# Today plus a later hour passes both field rules on its own and is still a
# visit that has not happened.
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

it('lets a salesperson correct the visit they logged', function () {
    $salesperson = visitSalesperson();
    $visit = Visit::factory()->loggedBy($salesperson)->create();

    $this->actingAs($salesperson)
        ->get(route('admin.visits.edit', $visit))
        ->assertOk();
});

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
    Visit::factory()->count(2)->for_purpose(VisitPurpose::AfterSales)->create();
    Visit::factory()->count(3)->for_purpose(VisitPurpose::Order)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['purpose' => VisitPurpose::AfterSales->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 2));
});

it('narrows the list to a window of dates', function () {
    Visit::factory()->create(['visited_at' => '2026-02-09 16:00']);
    Visit::factory()->count(2)->create(['visited_at' => '2026-02-14 11:00']);
    Visit::factory()->create(['visited_at' => '2026-03-01 09:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-10', 'to' => '2026-02-28']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 2)
            ->where('filters.from', '2026-02-10')
            ->where('filters.to', '2026-02-28')
            ->where('date_label', '2026-02-10 to 2026-02-28'));
});

it('leaves the far end open where only the near one was picked', function () {
    Visit::factory()->create(['visited_at' => '2026-02-09 16:00']);
    Visit::factory()->count(2)->create(['visited_at' => '2026-02-14 11:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 2)
            ->where('filters.to', '')
            ->where('date_label', 'From 2026-02-10'));
});

# The `to` end is a date and `visited_at` a datetime: read as a bare midnight
# the closing day would hold nothing that happened during it.
it('counts the whole of the closing day, not the midnight that opens it', function () {
    Visit::factory()->create(['visited_at' => '2026-02-28 23:59:00']);
    Visit::factory()->create(['visited_at' => '2026-03-01 00:01:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 1));
});

it('reads a window handed over back to front as the same window', function () {
    Visit::factory()->create(['visited_at' => '2026-02-14 11:00']);
    Visit::factory()->create(['visited_at' => '2026-03-20 11:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => '2026-02-28', 'to' => '2026-02-01']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 1)
            ->where('filters.from', '2026-02-01')
            ->where('filters.to', '2026-02-28'));
});

it('reads a window it cannot make sense of as no window at all', function () {
    Visit::factory()->count(3)->create(['visited_at' => '2026-02-14 11:00']);

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['from' => 'last tuesday', 'to' => '28/02/2026']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.total', 3)
            ->where('filters.from', '')
            ->where('filters.to', '')
            ->where('date_label', 'All dates'));
});

it('carries the window into the download', function () {
    Excel::fake();

    $manager = visitStaff([
        Permission::VisitsViewAny,
        Permission::VisitsViewOwn,
        Permission::VisitsExport,
    ]);

    $inside = Visit::factory()->create(['visited_at' => '2026-02-14 11:00']);
    Visit::factory()->create(['visited_at' => '2026-03-01 09:00']);

    $this->actingAs($manager)
        ->get(route('admin.visits.export', ['from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertSuccessful();

    Excel::assertDownloaded(
        'visits-'.now()->toDateString().'.csv',
        fn (VisitExport $export) => $export->query()->pluck('id')->all() === [$inside->id],
    );
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

# Guards a regression: under UTC an afternoon time read as the future and was
# pulled back to the moment of entry. Pairs with the timezone test below.
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

it('falls back to the logger when no respondent was recorded', function () {
    $manager = visitManager();

    Visit::factory()->loggedBy($manager)->create(['respondent' => null]);

    $this->actingAs($manager)
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('visits.data.0.attended_by', $manager->name));
});

# =========================================================================
# Finding or typing the customer
# =========================================================================

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

it('records both the person and the company for a company visit', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), newCustomerPayload([
        'customer_type' => CustomerType::Company->value,
        'customer_name' => 'Peter Mwangi',
        'company_name' => 'Mwangi Builders Ltd',
        'segment' => CustomerSegment::Corporate->value,
    ]));

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->name)->toBe('Peter Mwangi')
        ->and($customer->company_name)->toBe('Mwangi Builders Ltd')
        ->and($customer->segment)->toBe(CustomerSegment::Corporate->value)
        ->and($customer->displayName())->toBe('Mwangi Builders Ltd');
});

it('stores a segment typed under Other on the visit form as written', function () {
    $this->actingAs(visitManager())->post(route('admin.visits.store'), newCustomerPayload([
        'customer_type' => CustomerType::Company->value,
        'customer_name' => 'Peter Mwangi',
        'company_name' => 'Boat Yard Ltd',
        'segment' => 'Boat yards',
    ]));

    expect(Customer::query()->sole()->segment)->toBe('Boat yards');
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

# =========================================================================
# Follow-up
# =========================================================================

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

# =========================================================================
# Nature of visit is free text, and the menu is only a suggestion
# =========================================================================

it('stores a typed nature of visit exactly as it was written', function () {
    $user = visitStaff([Permission::VisitsViewAny, Permission::VisitsCreate]);

    $this->actingAs($user)
        ->post(route('admin.visits.store'), visitPayload(['purpose' => 'Warranty claim']))
        ->assertRedirect();

    expect(Visit::query()->sole()->purpose)->toBe('Warranty claim');
});

it('reads a typed nature of visit back as written and a known one by its label', function () {
    expect(VisitPurpose::readable('Warranty claim'))->toBe('Warranty claim')
        ->and(VisitPurpose::readable('after_sales'))->toBe('After-sales / service');
});

it('filters the log by a typed nature of visit', function () {
    $user = visitStaff([Permission::VisitsViewAny]);

    Visit::factory()->create(['purpose' => 'Warranty claim']);
    Visit::factory()->for_purpose(VisitPurpose::Collection)->create();

    $this->actingAs($user)
        ->get(route('admin.visits.index', ['purpose' => 'Warranty claim']))
        ->assertInertia(fn ($page) => $page->has('visits.data', 1));
});

it('offers the IT desk, spelled the way it is written', function () {
    expect(VisitDepartment::values())->toContain('it')
        ->and(VisitDepartment::It->label())->toBe('IT')
        ->and(VisitDepartment::readable('it'))->toBe('IT');
});

it('offers collection and delivery as two separate errands', function () {
    expect(VisitPurpose::values())
        ->toContain('collection')
        ->toContain('delivery')
        ->and(VisitPurpose::Collection->label())->toBe('Collection')
        ->and(VisitPurpose::Delivery->label())->toBe('Delivery')
        # Rows written while the two shared one option keep their value and
        # still read back - the column is free text, so nothing was migrated.
        ->and(VisitPurpose::readable('collection'))->toBe('Collection');
});

it('no longer offers the two purposes nobody ever used', function () {
    expect(VisitPurpose::values())
        ->not->toContain('follow_up')
        ->not->toContain('complaint');
});

# =========================================================================
# Source is free text too, and a referral names who made it
# =========================================================================

it('stores a typed source exactly as it was written', function () {
    $user = visitStaff([Permission::VisitsViewAny, Permission::VisitsCreate]);

    $this->actingAs($user)
        ->post(route('admin.visits.store'), visitPayload([
            'source' => 'Trade fair stand',
            'referred_by' => null,
        ]))
        ->assertRedirect();

    expect(Visit::query()->sole()->source)->toBe('Trade fair stand');
});

it('reads a typed source back as written and a known one by its label', function () {
    expect(CustomerSource::readable('Trade fair stand'))->toBe('Trade fair stand')
        ->and(CustomerSource::readable('social_media'))->toBe('Social media');
});

it('no longer offers the three sources nobody ever used', function () {
    expect(CustomerSource::values())
        ->not->toContain('repeat')
        ->not->toContain('advertisement')
        ->not->toContain('sales_call');
});

it('refuses a referral that does not say who made it', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'source' => CustomerSource::Referral->value,
            'referred_by' => '',
        ]))
        ->assertSessionHasErrors('referred_by');

    expect(Visit::query()->count())->toBe(0);
});

it('refuses a referrer against a source that is not a referral', function () {
    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), visitPayload([
            'source' => CustomerSource::WalkIn->value,
            'referred_by' => 'Mary Wanjiru',
        ]))
        ->assertSessionHasErrors('referred_by');

    expect(Visit::query()->count())->toBe(0);
});

# `prohibited` leaves the key out of `validated()`, so nothing would clear the
# column if `visitAttributes()` did not write it unconditionally.
it('drops the referrer when the visit moves off a referral', function () {
    $visit = Visit::factory()->referredBy('Mary Wanjiru')->create();

    $this->actingAs(visitManager())
        ->patch(route('admin.visits.update', $visit), visitPayload([
            'source' => CustomerSource::WalkIn->value,
            'referred_by' => '',
        ]))
        ->assertRedirect(route('admin.visits.index'));

    expect($visit->fresh()->referred_by)->toBeNull();
});

# =========================================================================
# Department reaches the form, the list, the filter and the download
# =========================================================================

it('stores a typed department exactly as it was written', function () {
    $user = visitStaff([Permission::VisitsViewAny, Permission::VisitsCreate]);

    $this->actingAs($user)
        ->post(route('admin.visits.store'), visitPayload(['department' => 'Fabrication']))
        ->assertRedirect();

    expect(Visit::query()->sole()->department)->toBe('Fabrication');
});

it('reads a typed department back as written and a known one by its label', function () {
    expect(VisitDepartment::readable('Fabrication'))->toBe('Fabrication')
        ->and(VisitDepartment::readable('showroom'))->toBe('Showroom');
});

it('offers the showroom and the sales desk separately', function () {
    expect(VisitDepartment::values())
        ->toContain('showroom')
        ->toContain('sales')
        ->not->toContain('showroom_sales')
        ->and(VisitDepartment::Showroom->label())->toBe('Showroom')
        ->and(VisitDepartment::Sales->label())->toBe('Sales');
});

it('still names the joint desk the visits already on file were filed under', function () {
    # Off the menu, but not unreadable: the log, the filters and the dashboard
    # all print through `readable()`, and the rows were never migrated.
    expect(VisitDepartment::readable('showroom_sales'))->toBe('Showroom/Sales');
});

it('refuses a visit that names no department', function () {
    $payload = visitPayload();
    unset($payload['department']);

    $this->actingAs(visitManager())
        ->post(route('admin.visits.store'), $payload)
        ->assertSessionHasErrors('department');

    expect(Visit::query()->count())->toBe(0);
});

it('narrows the list to one department', function () {
    Visit::factory()->count(2)->for_department(VisitDepartment::Finance)->create();
    Visit::factory()->count(3)->for_department(VisitDepartment::Logistics)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['department' => VisitDepartment::Finance->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 2));
});

it('filters the log by a typed department', function () {
    Visit::factory()->create(['department' => 'Fabrication']);
    Visit::factory()->for_department(VisitDepartment::Stores)->create();

    $this->actingAs(visitManager())
        ->get(route('admin.visits.index', ['department' => 'Fabrication']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('visits.data', 1));
});

it('offers the list and the form the departments to choose between', function () {
    $this->actingAs(visitManager())
        ->get(route('admin.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('departments', count(VisitDepartment::cases())));

    $this->actingAs(visitManager())
        ->get(route('admin.visits.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('departments', count(VisitDepartment::cases())));
});
