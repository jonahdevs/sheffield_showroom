<?php

use App\Enums\CustomerSource;
use App\Enums\Permission;
use App\Enums\VisitPurpose;
use App\Exports\DashboardSummaryExport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use App\Services\Documents\TableDocumentService;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function dashboardStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'dashboard-'.fake()->unique()->word(),
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/** Somebody who runs the floor: the dashboard, measured over every visit. */
function dashboardManager(): User
{
    return dashboardStaff([
        Permission::DashboardView,
        Permission::VisitsViewAny,
        Permission::VisitsViewOwn,
    ]);
}

/** A salesperson: the dashboard, measured over the visits they logged. */
function dashboardSalesperson(): User
{
    return dashboardStaff([
        Permission::DashboardView,
        Permission::VisitsViewOwn,
    ]);
}

/**
 * A visit that landed on a given day, which is what every panel is sliced by.
 *
 * @param  array<string, mixed>  $attributes
 */
function visitOn(int $daysAgo, array $attributes = []): Visit
{
    return Visit::factory()->create([
        'visited_at' => now()->subDays($daysAgo)->setTime(11, 0),
        'expected_follow_up_on' => null,
        ...$attributes,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

it('refuses the dashboard to somebody without the permission', function () {
    $this->actingAs(dashboardStaff([Permission::VisitsViewAny]))
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('opens the dashboard over the last seven days by default', function () {
    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('range.preset', 'last_7_days')
            ->where('range.days', 7)
            ->has('trend', 7));
});

it('takes the window from the range the page was asked for', function () {
    $this->actingAs(dashboardManager())
        ->get(route('dashboard', ['range' => 'last_30_days']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('range.preset', 'last_30_days')
            ->where('range.days', 30)
            ->has('trend', 30));
});

it('counts only the visits that landed inside the window', function () {
    visitOn(2);
    visitOn(4);
    visitOn(20);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.0.value', 2));
});

/**
 * The whole reason the range is one object: the delta is measured against the
 * seven days before these seven, not against the log as a whole.
 */
it('measures the window against the equally long one before it', function () {
    visitOn(1);
    visitOn(2);
    visitOn(3);
    visitOn(4);

    visitOn(9);
    visitOn(10);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 4)
            ->where('stats.0.previous', 2)
            /* A whole percentage comes back over the wire as a whole number,
               which is what the assertion has to compare against. */
            ->where('stats.0.change', 100));
});

/** Everything is a rise on nothing, and saying so would be noise. */
it('leaves the change unstated when the window before it held nothing', function () {
    visitOn(1);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.0.change', null));
});

it('counts the people behind the visits and how many were new to the window', function () {
    $returning = Customer::factory()->create();
    $fresh = Customer::factory()->create();

    visitOn(40, ['customer_id' => $returning->id]);
    visitOn(2, ['customer_id' => $returning->id]);
    visitOn(3, ['customer_id' => $fresh->id]);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            /* Keyed as well as indexed: the row is positional everywhere else
               in this file, and a tile added or dropped should fail here
               rather than quietly move what the numbers below are about. */
            ->has('stats', 4)
            ->where('stats.1.key', 'customers')
            ->where('stats.1.value', 2)
            ->where('stats.2.key', 'new_customers')
            ->where('stats.2.value', 1)
            /* No returning tile between them. It would be 2 - 1, read off the
               two tiles either side of where it used to sit. */
            ->where('stats.3.key', 'product_interests'));
});

it('divides the window by purpose and says what share each wedge is', function () {
    visitOn(1, ['purpose' => VisitPurpose::Quotation]);
    visitOn(2, ['purpose' => VisitPurpose::Quotation]);
    visitOn(3, ['purpose' => VisitPurpose::Complaint]);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('purposes', 2)
            ->where('purposes.0.value', VisitPurpose::Quotation->value)
            ->where('purposes.0.count', 2)
            ->where('purposes.0.share', 66.7)
            ->where('purposes.1.value', VisitPurpose::Complaint->value));
});

it('divides the window by where the customer came from', function () {
    visitOn(1, ['source' => CustomerSource::WalkIn]);
    visitOn(2, ['source' => CustomerSource::WalkIn]);
    visitOn(3, ['source' => CustomerSource::Website]);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sources', 2)
            ->where('sources.0.value', CustomerSource::WalkIn->value)
            ->where('sources.0.count', 2));
});

it('ranks the products by how many visits named them', function () {
    $asked = Product::factory()->create();
    $glanced = Product::factory()->create();

    visitOn(1)->products()->attach([$asked->id, $glanced->id]);
    visitOn(2)->products()->attach($asked->id);
    visitOn(20)->products()->attach($glanced->id);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products', 2)
            ->where('products.0.id', $asked->id)
            ->where('products.0.visits', 2)
            ->where('products.1.visits', 1)
            /* Three attachments inside the window, and the one on the visit
               outside it left where it belongs. */
            ->where('stats.3.value', 3));
});

it('totals each respondent\'s visits, customers and follow-ups', function () {
    $regular = Customer::factory()->create();

    visitOn(1, [
        'respondent' => 'Achieng Odhiambo',
        'customer_id' => $regular->id,
        'expected_follow_up_on' => now()->addWeek(),
    ]);
    visitOn(2, ['respondent' => 'Achieng Odhiambo', 'customer_id' => $regular->id]);
    visitOn(3, [
        'respondent' => 'Achieng Odhiambo',
        'expected_follow_up_on' => now()->addWeek(),
    ]);
    visitOn(4, ['respondent' => 'Brian Mwangi']);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('respondents', 2)
            ->where('respondents.0.name', 'Achieng Odhiambo')
            ->where('respondents.0.visits', 3)
            ->where('respondents.0.customers', 2)
            ->where('respondents.0.follow_ups', 2)
            ->where('respondents.1.visits', 1));
});

/**
 * The same boundary the visits list draws. A salesperson's dashboard is a
 * report on their own week, not a window onto the floor's.
 */
it('measures a salesperson against the visits they logged', function () {
    $salesperson = dashboardSalesperson();

    visitOn(1, ['created_by' => $salesperson->id]);
    visitOn(2, ['created_by' => $salesperson->id]);
    visitOn(3);
    visitOn(4);

    $this->actingAs($salesperson)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 2)
            ->has('recent', 2)
            ->where('scoped_to_own', true));
});

it('names a customer another salesperson already met as new to this one', function () {
    $salesperson = dashboardSalesperson();
    $customer = Customer::factory()->create();

    visitOn(40, ['customer_id' => $customer->id]);
    visitOn(2, ['customer_id' => $customer->id, 'created_by' => $salesperson->id]);

    $this->actingAs($salesperson)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.2.value', 1));
});

/**
 * The showroom opened this week and nobody came. Every panel still has to hand
 * the page something it can draw an empty state from.
 */
it('hands every panel back empty when nothing landed in the window', function () {
    visitOn(30);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.0.value', 0)
            ->where('stats.0.change', null)
            ->has('trend', 7)
            ->where('trend.0.visits', 0)
            ->where('purposes', [])
            ->where('sources', [])
            ->where('products', [])
            ->where('respondents', [])
            ->where('recent', []));
});

// -----------------------------------------------------------------------------
// The window
// -----------------------------------------------------------------------------

it('takes a window picked on the calendar', function () {
    $from = now()->subDays(20)->format('Y-m-d');
    $to = now()->subDays(11)->format('Y-m-d');

    visitOn(15);
    visitOn(2);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard', ['range' => 'custom', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('range.preset', 'custom')
            ->where('range.from', $from)
            ->where('range.to', $to)
            ->where('range.days', 10)
            ->where('stats.0.value', 1));
});

/** A query string is hand-typed as often as it is clicked. */
it('turns a window picked back to front the right way round', function () {
    $from = now()->subDays(3)->format('Y-m-d');
    $to = now()->subDays(9)->format('Y-m-d');

    $this->actingAs(dashboardManager())
        ->get(route('dashboard', ['range' => 'custom', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('range.from', $to)
            ->where('range.to', $from));
});

it('falls back to the default window when the dates will not parse', function () {
    $this->actingAs(dashboardManager())
        ->get(route('dashboard', ['range' => 'custom', 'from' => 'yesterday', 'to' => '']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('range.preset', 'last_7_days')
            ->where('range.days', 7));
});

/**
 * The trend line carries a point per day, so an unbounded window is a chart
 * nobody can read paid for with a query nobody wanted.
 */
it('caps a window longer than a year', function () {
    $this->actingAs(dashboardManager())
        ->get(route('dashboard', [
            'range' => 'custom',
            'from' => now()->subYears(5)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('range.days', 366));
});

it('pulls a window ending in the future back to today', function () {
    $this->actingAs(dashboardManager())
        ->get(route('dashboard', [
            'range' => 'custom',
            'from' => now()->subDays(3)->format('Y-m-d'),
            'to' => now()->addMonth()->format('Y-m-d'),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('range.to', now()->format('Y-m-d'))
            ->where('range.days', 4));
});

// -----------------------------------------------------------------------------
// The download
// -----------------------------------------------------------------------------

it('hands the dashboard back as a spreadsheet of the same figures', function () {
    Excel::fake();

    visitOn(1, ['purpose' => VisitPurpose::Quotation]);
    visitOn(2, ['purpose' => VisitPurpose::Quotation]);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard.export', ['format' => 'xlsx']))
        ->assertOk();

    Excel::assertDownloaded(
        'showroom-dashboard-'.now()->subDays(6)->format('Y-m-d').'-to-'.now()->format('Y-m-d').'.xlsx',
        function (DashboardSummaryExport $export) {
            $rows = $export->array();

            $totalVisits = collect($rows)->firstWhere(1, 'Total visits');
            $quotations = collect($rows)
                ->where(0, 'Visits by purpose')
                ->firstWhere(1, VisitPurpose::Quotation->label());

            return $totalVisits[2] === 2 && $quotations[2] === 2;
        },
    );
});

it('narrows the download to the window the page was showing', function () {
    Excel::fake();

    visitOn(2);
    visitOn(40);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard.export', ['range' => 'last_90_days', 'format' => 'csv']))
        ->assertOk();

    Excel::assertDownloaded(
        'showroom-dashboard-'.now()->subDays(89)->format('Y-m-d').'-to-'.now()->format('Y-m-d').'.csv',
        fn (DashboardSummaryExport $export) => collect($export->array())
            ->firstWhere(1, 'Total visits')[2] === 2,
    );
});

/** A salesperson downloads their own week, the same as they read it. */
it('narrows the download to the visits the salesperson may see', function () {
    Excel::fake();

    $salesperson = dashboardSalesperson();

    visitOn(1, ['created_by' => $salesperson->id]);
    visitOn(2);

    $this->actingAs($salesperson)
        ->get(route('dashboard.export'))
        ->assertOk();

    Excel::assertDownloaded(
        'showroom-dashboard-'.now()->subDays(6)->format('Y-m-d').'-to-'.now()->format('Y-m-d').'.csv',
        fn (DashboardSummaryExport $export) => collect($export->array())
            ->firstWhere(1, 'Total visits')[2] === 1,
    );
});

it('refuses the download to somebody without the dashboard permission', function () {
    $this->actingAs(dashboardStaff([Permission::VisitsExport]))
        ->get(route('dashboard.export'))
        ->assertForbidden();
});

it('offers only the download formats this host can produce', function () {
    $expected = TableDocumentService::available()
        ? ['csv', 'xlsx', 'pdf']
        : ['csv', 'xlsx'];

    $this->actingAs(dashboardManager())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('formats', $expected));
});

/**
 * A host without headless Chrome is a deployment fact rather than a bug in the
 * request, so the reader is told rather than shown a stack trace.
 */
it('says so rather than downloading when the PDF renderer is missing', function () {
    visitOn(1);

    $this->actingAs(dashboardManager())
        ->get(route('dashboard.export', ['format' => 'pdf']))
        ->assertRedirect();
})->skip(
    TableDocumentService::available(),
    'The PDF renderer is installed on this host.',
);
