<?php

use App\Enums\Permission;
use App\Enums\ProductSource;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  array<int, Permission>  $permissions
 */
function syncStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'catalogue',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function fakeCatalogue(array $rows, int $lastPage = 1): void
{
    Http::fake([
        '*/api/catalogue/products*' => Http::response([
            'data' => $rows,
            'meta' => ['current_page' => 1, 'last_page' => $lastPage, 'per_page' => 200, 'total' => count($rows)],
        ]),
    ]);
}

beforeEach(function () {
    config()->set('services.main_website.url', 'https://sheffieldafrica.test');
    config()->set('services.main_website.token', 'test-token');
});

function catalogueSyncer(): User
{
    return syncStaff([
        Permission::ProductsViewAny,
        Permission::ProductsCreate,
        Permission::ProductsUpdate,
    ]);
}

it('refuses the sync without both create and update', function () {
    $user = syncStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    $this->actingAs($user)
        ->post(route('admin.products.sync'))
        ->assertForbidden();
});

it('imports products from the website', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Galvanised Sheet', 'sku' => 'SS-0001', 'image_url' => 'https://sheffieldafrica.test/storage/a.jpg'],
        ['id' => 12, 'name' => 'Roofing Nail', 'sku' => 'SS-0002', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);

    $sheet = Product::query()->where('external_id', 11)->sole();

    expect($sheet->name)->toBe('Galvanised Sheet')
        ->and($sheet->sku)->toBe('SS-0001')
        ->and($sheet->source)->toBe(ProductSource::Website)
        ->and($sheet->synced_at)->not->toBeNull()
        ->and($sheet->imageUrl())->toBe('https://sheffieldafrica.test/storage/a.jpg');
});

it('sends the token the website expects', function () {
    fakeCatalogue([]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    Http::assertSent(fn ($request) => $request->hasHeader('X-Catalogue-Token', 'test-token'));
});

/**
 * The whole reason `external_id` exists: a second run must update what the
 * first one made rather than adding it again.
 */
it('updates rather than duplicates on a second run', function () {
    /* A sequence rather than two `Http::fake` calls: stubs merge, so a second
       `fake` would leave the first one still matching and both runs would see
       the same payload. */
    $page = fn (string $name) => [
        'data' => [['id' => 11, 'name' => $name, 'sku' => 'SS-0001', 'image_url' => null]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => 1],
    ];

    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push($page('Galvanised Sheet'))
            ->push($page('Galvanised Sheet Mk II')),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));
    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->sole()->name)->toBe('Galvanised Sheet Mk II');
});

/**
 * A product typed in here before the sync existed is the same steel sheet, so
 * it is adopted on its SKU rather than appearing twice.
 */
it('adopts a hand-entered product that shares a SKU', function () {
    $existing = Product::factory()->create([
        'name' => 'Sheet typed in by hand',
        'sku' => 'SS-0001',
    ]);

    fakeCatalogue([
        ['id' => 11, 'name' => 'Galvanised Sheet', 'sku' => 'SS-0001', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1);

    $existing->refresh();

    expect($existing->external_id)->toBe(11)
        ->and($existing->name)->toBe('Galvanised Sheet')
        ->and($existing->source)->toBe(ProductSource::Website);
});

it('leaves a hand-entered product with no matching SKU alone', function () {
    Product::factory()->create(['name' => 'Local only', 'sku' => 'LOCAL-1']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'Galvanised Sheet', 'sku' => 'SS-0001', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('sku', 'LOCAL-1')->sole()->source)
        ->toBe(ProductSource::Manual);
});

it('brings back a synced product that was removed here', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Galvanised Sheet', 'sku' => 'SS-0001', 'image_url' => null],
    ]);

    /* One stub, hit twice - the payload is the same both times here. */
    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));

    Product::query()->sole()->delete();

    expect(Product::query()->count())->toBe(0);

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1);
});

/**
 * The column is unique, so a blank must be null and a duplicate must be
 * dropped - otherwise one bad row on the website stops the whole sync.
 */
it('survives blank and duplicated SKUs from the website', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'No code', 'sku' => '', 'image_url' => null],
        ['id' => 12, 'name' => 'Also no code', 'sku' => null, 'image_url' => null],
        ['id' => 13, 'name' => 'First with SS-9', 'sku' => 'SS-9', 'image_url' => null],
        ['id' => 14, 'name' => 'Second with SS-9', 'sku' => 'SS-9', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(4)
        ->and(Product::query()->whereNull('sku')->count())->toBe(3)
        ->and(Product::query()->where('sku', 'SS-9')->count())->toBe(1);
});

it('skips a row with no id or no name', function () {
    fakeCatalogue([
        ['id' => null, 'name' => 'Orphan', 'sku' => 'SS-1', 'image_url' => null],
        ['id' => 15, 'name' => '   ', 'sku' => 'SS-2', 'image_url' => null],
        ['id' => 16, 'name' => 'Good one', 'sku' => 'SS-3', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->sole()->name)->toBe('Good one');
});

it('reports a rejected token without creating anything', function () {
    Http::fake(['*/api/catalogue/products*' => Http::response(['message' => 'Invalid'], 401)]);

    $this->actingAs(catalogueSyncer())
        ->post(route('admin.products.sync'))
        ->assertRedirect();

    expect(Product::query()->count())->toBe(0);
});

it('reports a website error without creating anything', function () {
    Http::fake(['*/api/catalogue/products*' => Http::response('boom', 500)]);

    $this->actingAs(catalogueSyncer())
        ->post(route('admin.products.sync'))
        ->assertRedirect();

    expect(Product::query()->count())->toBe(0);
});

it('refuses to run with no token configured', function () {
    config()->set('services.main_website.token', null);

    Http::fake();

    $this->actingAs(catalogueSyncer())
        ->post(route('admin.products.sync'))
        ->assertRedirect();

    Http::assertNothingSent();
});

it('tells the index page whether a sync is possible', function () {
    $user = catalogueSyncer();

    $this->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page
            ->where('can.sync', true)
            ->where('sync_configured', true));

    config()->set('services.main_website.token', '');

    $this->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertInertia(fn ($page) => $page->where('sync_configured', false));
});

it('walks every page the website reports', function () {
    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push([
                'data' => [['id' => 1, 'name' => 'One', 'sku' => 'A-1', 'image_url' => null]],
                'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2],
            ])
            ->push([
                'data' => [['id' => 2, 'name' => 'Two', 'sku' => 'A-2', 'image_url' => null]],
                'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 1, 'total' => 2],
            ]),
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);
});

/**
 * The website holds hundreds of products whose SKU is the literal string
 * "null". Stored as it stands, a salesperson reads "null" off the tile.
 */
it('treats a placeholder SKU as no SKU', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Ice Machine', 'sku' => 'null', 'image_url' => null],
        ['id' => 12, 'name' => 'Chest Freezer', 'sku' => 'N/A', 'image_url' => null],
        ['id' => 13, 'name' => 'Display Chiller', 'sku' => ' - ', 'image_url' => null],
        ['id' => 14, 'name' => 'Real code', 'sku' => 'SS-0001', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(4)
        ->and(Product::query()->whereNull('sku')->count())->toBe(3)
        ->and(Product::query()->whereNotNull('sku')->sole()->sku)->toBe('SS-0001');
});

/**
 * A product taken down on the website should leave the showroom floor too.
 */
it('removes a synced product the website no longer offers', function () {
    $both = fn (array $rows) => [
        'data' => $rows,
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => count($rows)],
    ];

    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push($both([
                ['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null],
                ['id' => 12, 'name' => 'Discontinued', 'sku' => 'SS-2', 'image_url' => null],
            ]))
            ->push($both([
                ['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null],
            ])),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->sole()->external_id)->toBe(11)
        ->and(Product::withTrashed()->where('external_id', 12)->sole()->trashed())->toBeTrue();
});

it('never removes a product added here by hand', function () {
    $local = Product::factory()->create(['name' => 'Local only', 'sku' => 'LOCAL-1']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'From the website', 'sku' => 'SS-1', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2)
        ->and($local->fresh()->trashed())->toBeFalse();
});

/**
 * An empty feed is far more likely to be a broken website than a catalogue
 * that sells nothing, and acting on it would clear the floor.
 */
it('removes nothing when the website returns an empty catalogue', function () {
    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push([
                'data' => [['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null]],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => 1],
            ])
            ->push([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => 0],
            ]),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));
    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1);
});

/**
 * A run that never reached the last page has not seen the whole catalogue, so
 * pruning against what it did see would remove products off an unread page.
 */
it('removes nothing when a page fails part way through', function () {
    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 11, 'name' => 'One', 'sku' => 'SS-1', 'image_url' => null],
                    ['id' => 12, 'name' => 'Two', 'sku' => 'SS-2', 'image_url' => null],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => 2],
            ])
            ->push([
                'data' => [['id' => 11, 'name' => 'One', 'sku' => 'SS-1', 'image_url' => null]],
                'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2],
            ])
            /* Page two never arrives, however many times it is retried. */
            ->whenEmpty(Http::response('boom', 500)),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);

    /* Page one lands, page two falls over: the run throws before pruning. */
    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);
});
