<?php

use App\Enums\Permission;
use App\Enums\ProductSource;
use App\Enums\ProductStatus;
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

it('imports the model number under either key the website uses', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Box Profile', 'sku' => 'SS-0001', 'model_number' => 'BP-28', 'image_url' => null],
        ['id' => 12, 'name' => 'Gutter', 'sku' => 'SS-0002', 'model' => 'GT-400', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->where('external_id', 11)->sole()->model_number)->toBe('BP-28')
        ->and(Product::query()->where('external_id', 12)->sole()->model_number)->toBe('GT-400');
});

it('stores a blank or placeholder model number as nothing', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'No model', 'sku' => 'SS-0001', 'model_number' => 'null', 'image_url' => null],
        ['id' => 12, 'name' => 'Also none', 'sku' => 'SS-0002', 'model_number' => '  ', 'image_url' => null],
        ['id' => 13, 'name' => 'Never said', 'sku' => 'SS-0003', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->pluck('model_number')->unique()->all())->toBe([null]);
});

it('sends the token the website expects', function () {
    fakeCatalogue([]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    Http::assertSent(fn ($request) => $request->hasHeader('X-Catalogue-Token', 'test-token'));
});

it('updates rather than duplicates on a second run', function () {
    # A sequence rather than two `Http::fake` calls: stubs merge, so a second
    # `fake` would leave the first still matching and both runs would see the
    # same payload.
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

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));

    Product::query()->sole()->delete();

    expect(Product::query()->count())->toBe(0);

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(1);
});

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
            # Page two never arrives, however many times it is retried.
            ->whenEmpty(Http::response('boom', 500)),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);

    # Page one lands, page two falls over: the run throws before pruning.
    $this->actingAs($user)->post(route('admin.products.sync'));

    expect(Product::query()->count())->toBe(2);
});

# =========================================================================
# Status, which the sync derives rather than the website dictating
# =========================================================================

it('maps what the website publishes onto a status', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'On sale', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => true],
        ['id' => 12, 'name' => 'Held back', 'sku' => 'SS-2', 'image_url' => null, 'is_published' => false],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->where('external_id', 11)->sole()->status)
        ->toBe(ProductStatus::Published)
        ->and(Product::query()->where('external_id', 12)->sole()->status)
        ->toBe(ProductStatus::Draft);
});

it('reads the published flag however the feed spells it', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'One', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => 1],
        ['id' => 12, 'name' => 'Two', 'sku' => 'SS-2', 'image_url' => null, 'is_published' => '0'],
        ['id' => 13, 'name' => 'Three', 'sku' => 'SS-3', 'image_url' => null, 'is_published' => 'true'],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->where('external_id', 11)->sole()->status)->toBe(ProductStatus::Published)
        ->and(Product::query()->where('external_id', 12)->sole()->status)->toBe(ProductStatus::Draft)
        ->and(Product::query()->where('external_id', 13)->sole()->status)->toBe(ProductStatus::Published);
});

it('never overwrites a status somebody set to Inactive here', function () {
    $product = Product::factory()
        ->fromWebsite(11)
        ->status(ProductStatus::Inactive)
        ->create(['name' => 'Held off the floor', 'sku' => 'SS-1']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'Held off the floor', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => true],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect($product->fresh()->status)->toBe(ProductStatus::Inactive);
});

it('leaves Inactive alone even when the website has unpublished the product', function () {
    $product = Product::factory()
        ->fromWebsite(11)
        ->status(ProductStatus::Inactive)
        ->create(['name' => 'Held off the floor', 'sku' => 'SS-1']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'Held off the floor', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => false],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect($product->fresh()->status)->toBe(ProductStatus::Inactive);
});

it('leaves a local status alone when the payload carries no status fields', function () {
    $draft = Product::factory()->fromWebsite(11)->status(ProductStatus::Draft)
        ->create(['name' => 'Not ready', 'sku' => 'SS-1']);
    $inactive = Product::factory()->fromWebsite(12)->status(ProductStatus::Inactive)
        ->create(['name' => 'Put away', 'sku' => 'SS-2']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'Not ready', 'sku' => 'SS-1', 'image_url' => null],
        ['id' => 12, 'name' => 'Put away', 'sku' => 'SS-2', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect($draft->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($inactive->fresh()->status)->toBe(ProductStatus::Inactive);
});

it('publishes a product the thin payload introduces for the first time', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Brand new', 'sku' => 'SS-1', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect(Product::query()->sole()->status)->toBe(ProductStatus::Published);
});

it('archives and removes a product the website reports as withdrawn', function () {
    fakeCatalogue([
        ['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => true],
        ['id' => 12, 'name' => 'Withdrawn', 'sku' => 'SS-2', 'image_url' => null, 'is_published' => true, 'deleted_at' => '2026-08-01T00:00:00+00:00'],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    $withdrawn = Product::withTrashed()->where('external_id', 12)->sole();

    expect($withdrawn->status)->toBe(ProductStatus::Archived)
        ->and($withdrawn->trashed())->toBeTrue()
        ->and(Product::query()->count())->toBe(1);
});

it('archives a product the website has stopped offering', function () {
    $both = fn (array $rows) => [
        'data' => $rows,
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'total' => count($rows)],
    ];

    Http::fake([
        '*/api/catalogue/products*' => Http::sequence()
            ->push($both([
                ['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => true],
                ['id' => 12, 'name' => 'Discontinued', 'sku' => 'SS-2', 'image_url' => null, 'is_published' => true],
            ]))
            ->push($both([
                ['id' => 11, 'name' => 'Still sold', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => true],
            ])),
    ]);

    $user = catalogueSyncer();

    $this->actingAs($user)->post(route('admin.products.sync'));
    $this->actingAs($user)->post(route('admin.products.sync'));

    $gone = Product::withTrashed()->where('external_id', 12)->sole();

    expect($gone->trashed())->toBeTrue()
        ->and($gone->status)->toBe(ProductStatus::Archived);
});

it('puts an archived product back on the floor when the website offers it again', function () {
    $product = Product::factory()->fromWebsite(11)->status(ProductStatus::Archived)
        ->create(['name' => 'Back in stock', 'sku' => 'SS-1']);

    $product->delete();

    fakeCatalogue([
        ['id' => 11, 'name' => 'Back in stock', 'sku' => 'SS-1', 'image_url' => null],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    $product->refresh();

    expect($product->trashed())->toBeFalse()
        ->and($product->status)->toBe(ProductStatus::Published);
});

it('never touches the status of a product added here by hand', function () {
    $local = Product::factory()->status(ProductStatus::Inactive)
        ->create(['name' => 'Local only', 'sku' => 'LOCAL-1']);

    fakeCatalogue([
        ['id' => 11, 'name' => 'From the website', 'sku' => 'SS-1', 'image_url' => null, 'is_published' => false],
    ]);

    $this->actingAs(catalogueSyncer())->post(route('admin.products.sync'));

    expect($local->fresh()->status)->toBe(ProductStatus::Inactive)
        ->and($local->fresh()->trashed())->toBeFalse();
});
