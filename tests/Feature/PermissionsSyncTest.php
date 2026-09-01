<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Database\Seeders\RolesSeeder;
use Spatie\Permission\Models\Permission;

it('creates every permission in the enum', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->pluck('name')->all())
        ->toEqualCanonicalizing(PermissionEnum::values());
});

it('seeds the roles RolesSeeder defines, with their permissions', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $superAdmin = Role::query()->where('name', Role::SUPER_ADMIN)->sole();

    expect($superAdmin->is_system)->toBeTrue()
        ->and($superAdmin->permissions)->toHaveCount(count(PermissionEnum::cases()));

    $sales = Role::query()->where('name', 'sales')->sole();

    expect($sales->is_system)->toBeFalse()
        ->and($sales->permissions->pluck('name'))
        ->toContain(PermissionEnum::VisitsCreate->value)
        ->not->toContain(PermissionEnum::RolesDelete->value);
});

it('runs twice without duplicating anything', function () {
    $this->artisan('permissions:sync')->assertSuccessful();
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(PermissionEnum::cases()))
        ->and(Role::query()->where('name', Role::SUPER_ADMIN)->count())->toBe(1);
});

it('leaves an edited non-system role alone on a re-run', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $sales = Role::query()->where('name', 'sales')->sole();
    $sales->syncPermissions([PermissionEnum::DashboardView->value]);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect($sales->refresh()->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::DashboardView->value]);
});

it('restores a system role that was tampered with', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $superAdmin = Role::query()->where('name', Role::SUPER_ADMIN)->sole();
    $superAdmin->syncPermissions([PermissionEnum::DashboardView->value]);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect($superAdmin->refresh()->permissions)
        ->toHaveCount(count(PermissionEnum::cases()));
});

it('leaves an orphaned permission in place without prune', function () {
    Permission::create(['name' => 'ghosts.haunt', 'guard_name' => 'web']);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::query()->where('name', 'ghosts.haunt')->exists())->toBeTrue();
});

it('deletes an orphaned permission with prune', function () {
    Permission::create(['name' => 'ghosts.haunt', 'guard_name' => 'web']);

    $this->artisan('permissions:sync', ['--prune' => true])->assertSuccessful();

    expect(Permission::query()->where('name', 'ghosts.haunt')->exists())->toBeFalse();
});

it('seeds roles and permissions when RolesSeeder runs on its own', function () {
    $this->seed(RolesSeeder::class);

    expect(Permission::query()->count())->toBe(count(PermissionEnum::cases()))
        ->and(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(array_keys(RolesSeeder::definitions()));
});

it('gives every role in the definitions the permissions it names', function () {
    $this->seed(RolesSeeder::class);

    foreach (RolesSeeder::definitions() as $name => $definition) {
        $role = Role::query()->where('name', $name)->sole();

        expect($role->permissions->pluck('name')->all())
            ->toEqualCanonicalizing(array_map(
                fn (PermissionEnum $permission) => $permission->value,
                $definition['permissions'],
            ));
    }
});
