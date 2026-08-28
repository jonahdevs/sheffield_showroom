<?php

use App\Enums\Permission;
use App\Enums\ProductSource;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function productStaff(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'floor',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

it('refuses the catalogue without products.view.any', function () {
    $user = productStaff([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

it('lists products to somebody who may view them', function () {
    $user = productStaff([Permission::ProductsViewAny]);

    Product::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/products/Index')
            ->where('products.total', 3));
});

it('records a product with a name alone', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    $this->actingAs($user)
        ->post(route('admin.products.store'), [
            'name' => 'Galvanised Corrugated Sheet',
            'sku' => null,
        ])
        ->assertRedirect(route('admin.products.index'));

    $product = Product::query()->sole();

    expect($product->name)->toBe('Galvanised Corrugated Sheet')
        ->and($product->sku)->toBeNull()
        ->and($product->image_path)->toBeNull()
        ->and($product->source)->toBe(ProductSource::Manual)
        ->and($product->created_by)->toBe($user->id);
});

it('requires a name', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    $this->actingAs($user)
        ->post(route('admin.products.store'), ['name' => '', 'sku' => 'SS-1'])
        ->assertSessionHasErrors('name');
});

it('stores an uploaded image on the public disk', function () {
    Storage::fake('public');

    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    $this->actingAs($user)
        ->post(route('admin.products.store'), [
            'name' => 'Mabati Sheet',
            'sku' => 'SS-0001',
            'image' => UploadedFile::fake()->image('sheet.jpg'),
        ])
        ->assertRedirect(route('admin.products.index'));

    $product = Product::query()->sole();

    expect($product->image_path)->toStartWith(Product::IMAGE_DIRECTORY.'/');

    Storage::disk('public')->assertExists($product->image_path);
});

it('rejects a file that is not an image', function () {
    Storage::fake('public');

    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    $this->actingAs($user)
        ->post(route('admin.products.store'), [
            'name' => 'Suspicious',
            'image' => UploadedFile::fake()->create('payload.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('image');

    expect(Product::query()->count())->toBe(0);
});

/**
 * An empty box is an uncoded product, not a product coded `''` - left as a
 * string the second one would collide on the unique index.
 */
it('keeps a blank SKU null so several can coexist', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    foreach (['First', 'Second'] as $name) {
        $this->actingAs($user)
            ->post(route('admin.products.store'), ['name' => $name, 'sku' => '  '])
            ->assertRedirect(route('admin.products.index'));
    }

    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->whereNull('sku')->count())->toBe(2);
});

it('refuses a SKU another product already holds', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsCreate]);

    Product::factory()->create(['sku' => 'SS-0001']);

    $this->actingAs($user)
        ->post(route('admin.products.store'), ['name' => 'Clash', 'sku' => 'SS-0001'])
        ->assertSessionHasErrors('sku');
});

it('lets a product keep its own SKU on update', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsUpdate]);

    $product = Product::factory()->create(['sku' => 'SS-0001', 'name' => 'Old']);

    $this->actingAs($user)
        ->post(route('admin.products.update', $product), [
            'name' => 'New',
            'sku' => 'SS-0001',
        ])
        ->assertRedirect(route('admin.products.index'));

    expect($product->refresh()->name)->toBe('New');
});

it('replaces the image and deletes the file it replaced', function () {
    Storage::fake('public');

    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsUpdate]);

    $product = Product::factory()->create([
        'image_path' => UploadedFile::fake()->image('old.jpg')
            ->store(Product::IMAGE_DIRECTORY, 'public'),
    ]);

    $old = $product->image_path;

    $this->actingAs($user)->post(route('admin.products.update', $product), [
        'name' => $product->name,
        'sku' => $product->sku,
        'image' => UploadedFile::fake()->image('new.jpg'),
    ]);

    $product->refresh();

    expect($product->image_path)->not->toBe($old);

    Storage::disk('public')->assertExists($product->image_path);
    Storage::disk('public')->assertMissing($old);
});

it('removes the image when asked without a replacement', function () {
    Storage::fake('public');

    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsUpdate]);

    $product = Product::factory()->create([
        'image_path' => UploadedFile::fake()->image('shown.jpg')
            ->store(Product::IMAGE_DIRECTORY, 'public'),
    ]);

    $old = $product->image_path;

    $this->actingAs($user)->post(route('admin.products.update', $product), [
        'name' => $product->name,
        'sku' => $product->sku,
        'remove_image' => true,
    ]);

    expect($product->refresh()->image_path)->toBeNull();

    Storage::disk('public')->assertMissing($old);
});

/**
 * A synced product points at the website's own URL. Deleting that file is not
 * this application's to do, and the string is not a path on our disk.
 */
it('never deletes a remote image belonging to the website', function () {
    Storage::fake('public');

    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsUpdate]);

    $product = Product::factory()->fromWebsite()->create([
        'image_path' => 'https://sheffieldafrica.com/storage/products/remote.jpg',
    ]);

    $this->actingAs($user)->post(route('admin.products.update', $product), [
        'name' => 'Renamed',
        'sku' => $product->sku,
        'remove_image' => true,
    ]);

    /* The point is that nothing tried to delete a path on our disk: the
       column held a URL, and the store is untouched either way. */
    expect($product->refresh()->image_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('serves a remote image URL as it stands and a local one through the disk', function () {
    $remote = Product::factory()->fromWebsite()->create([
        'image_path' => 'https://sheffieldafrica.com/storage/products/remote.jpg',
    ]);

    $local = Product::factory()->create(['image_path' => 'products/local.jpg']);

    expect($remote->imageUrl())->toBe('https://sheffieldafrica.com/storage/products/remote.jpg')
        ->and($local->imageUrl())->toEndWith('/storage/products/local.jpg');
});

it('soft deletes a product so the visits that showed it survive', function () {
    $user = productStaff([Permission::ProductsViewAny, Permission::ProductsDelete]);

    $product = Product::factory()->create();

    $this->actingAs($user)->delete(route('admin.products.destroy', $product));

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse()
        ->and(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
});

it('refuses to create or delete without the permission', function () {
    $user = productStaff([Permission::ProductsViewAny]);

    $this->actingAs($user)
        ->post(route('admin.products.store'), ['name' => 'Nope'])
        ->assertForbidden();

    $product = Product::factory()->create();

    $this->actingAs($user)
        ->delete(route('admin.products.destroy', $product))
        ->assertForbidden();
});

it('finds a product by name or SKU', function () {
    $user = productStaff([Permission::ProductsViewAny]);

    Product::factory()->create(['name' => 'Galvanised Sheet', 'sku' => 'SS-0001']);
    Product::factory()->create(['name' => 'Roofing Nail', 'sku' => 'SS-0002']);

    foreach (['Galvanised' => 1, 'SS-0002' => 1, 'SS-' => 2, 'nothing' => 0] as $term => $expected) {
        $this->actingAs($user)
            ->get(route('admin.products.index', ['search' => (string) $term]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.total', $expected));
    }
});

it('sends a first page of tiles rather than the whole catalogue', function () {
    $user = productStaff([Permission::ProductsViewAny]);

    Product::factory()->count(30)->create();

    $this->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 24)
            ->where('products.total', 30));
});

/**
 * The catalogue holds products that share a name to the letter. Ordered on
 * name alone their order is undefined, so page two could repeat a tile page
 * one already showed - which on an endless grid means a duplicate on screen.
 */
it('pages through products of identical name without repeating one', function () {
    $user = productStaff([Permission::ProductsViewAny]);

    Product::factory()->count(30)->create(['name' => 'UPRIGHT GLASS DOOR FREEZER']);

    $idsOn = function (int $page) use ($user): array {
        $ids = [];

        $this->actingAs($user)
            ->get(route('admin.products.index', ['page' => $page]))
            ->assertOk()
            ->assertInertia(function ($assert) use (&$ids) {
                $ids = collect($assert->toArray()['props']['products']['data'])
                    ->pluck('id')
                    ->all();
            });

        return $ids;
    };

    $first = $idsOn(1);
    $second = $idsOn(2);

    expect($first)->toHaveCount(24)
        ->and($second)->toHaveCount(6)
        ->and(array_intersect($first, $second))->toBeEmpty()
        ->and(array_unique([...$first, ...$second]))->toHaveCount(30);
});
