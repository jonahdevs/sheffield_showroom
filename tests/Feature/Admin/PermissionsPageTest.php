<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function viewerWith(array $permissions): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => 'viewer',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

it('refuses the permissions screen without roles.view', function () {
    $user = viewerWith([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.permissions.index'))
        ->assertForbidden();
});

it('lists every permission in the enum', function () {
    $user = viewerWith([Permission::RolesView]);

    $this->actingAs($user)
        ->get(route('admin.permissions.index', ['per_page' => 100]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Permissions')
            ->where('permissions.total', count(Permission::cases())));
});

it('lists permissions even when the table is empty', function () {
    $role = Role::query()->create([
        'name' => 'viewer',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    Spatie\Permission\Models\Permission::findOrCreate(Permission::RolesView->value, 'web');
    $role->syncPermissions([Permission::RolesView->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create()->assignRole($role);

    $this->actingAs($user)
        ->get(route('admin.permissions.index', ['per_page' => 100]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.total', count(Permission::cases())));
});

it('names the roles that hand a permission out', function () {
    $user = viewerWith([Permission::RolesView, Permission::VisitsCreate]);

    $this->actingAs($user)
        ->get(route('admin.permissions.index', [
            'search' => Permission::VisitsCreate->value,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.data.0.value', Permission::VisitsCreate->value)
            ->where('permissions.data.0.roles', ['viewer']));
});

it('filters by group', function () {
    $user = viewerWith([Permission::RolesView]);

    $expected = count(Permission::grouped()['visits']);

    $this->actingAs($user)
        ->get(route('admin.permissions.index', ['group' => 'visits', 'per_page' => 100]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('permissions.total', $expected));
});

it('filters by search', function () {
    $user = viewerWith([Permission::RolesView]);

    $this->actingAs($user)
        ->get(route('admin.permissions.index', ['search' => 'no-such-capability']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('permissions.total', 0));
});
