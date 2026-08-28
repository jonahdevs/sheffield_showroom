<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function userHolding(array $permissions, string $role = 'tester'): User
{
    foreach (Permission::values() as $name) {
        Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
    }

    $role = Role::query()->create([
        'name' => $role,
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

it('refuses the roles screen to a user without roles.view', function () {
    $user = userHolding([Permission::DashboardView]);

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

it('shows the roles screen to a user holding roles.view', function () {
    $user = userHolding([Permission::RolesView]);

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertOk();
});

it('creates a role with the permissions ticked', function () {
    $user = userHolding([
        Permission::RolesView,
        Permission::RolesCreate,
        Permission::VisitsViewAny,
        Permission::VisitsCreate,
    ]);

    $this->actingAs($user)
        ->post(route('admin.roles.store'), [
            'name' => 'floor-staff',
            'description' => 'Takes walk-ins.',
            'permissions' => [
                Permission::VisitsViewAny->value,
                Permission::VisitsCreate->value,
            ],
        ])
        ->assertRedirect(route('admin.roles.index'));

    $role = Role::query()->where('name', 'floor-staff')->sole();

    expect($role->is_system)->toBeFalse()
        ->and($role->description)->toBe('Takes walk-ins.')
        ->and($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            Permission::VisitsViewAny->value,
            Permission::VisitsCreate->value,
        ]);
});

/**
 * The rule the whole screen exists to hold: a role is not a way to hand
 * yourself something your own account was never given.
 */
it('refuses to grant a permission the creator does not hold', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesCreate]);

    $this->actingAs($user)
        ->post(route('admin.roles.store'), [
            'name' => 'sneaky',
            'description' => null,
            'permissions' => [Permission::CustomersDelete->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect(Role::query()->where('name', 'sneaky')->exists())->toBeFalse();
});

it('rejects a name that is not a slug', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesCreate]);

    $this->actingAs($user)
        ->post(route('admin.roles.store'), [
            'name' => 'Floor Staff',
            'description' => null,
            'permissions' => [],
        ])
        ->assertSessionHasErrors('name');
});

it('updates a role', function () {
    $user = userHolding([
        Permission::RolesView,
        Permission::RolesUpdate,
        Permission::ProductsViewAny,
    ]);

    $role = Role::query()->create([
        'name' => 'catalogue',
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.roles.update', $role), [
            'name' => 'catalogue',
            'description' => 'Reads the catalogue.',
            'permissions' => [Permission::ProductsViewAny->value],
        ])
        ->assertRedirect(route('admin.roles.index'));

    expect($role->refresh()->description)->toBe('Reads the catalogue.')
        ->and($role->permissions->pluck('name')->all())
        ->toBe([Permission::ProductsViewAny->value]);
});

it('opens a system role read only and refuses to update it', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesUpdate]);

    $system = Role::query()->create([
        'name' => 'shipped',
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $this->actingAs($user)
        ->get(route('admin.roles.edit', $system))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/Form')
            ->where('read_only', true));

    $this->actingAs($user)
        ->patch(route('admin.roles.update', $system), [
            'name' => 'renamed',
            'description' => null,
            'permissions' => [],
        ])
        ->assertForbidden();
});

it('refuses to delete a system role', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesDelete]);

    $system = Role::query()->create([
        'name' => 'shipped',
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('admin.roles.destroy', $system))
        ->assertForbidden();

    expect(Role::query()->whereKey($system->id)->exists())->toBeTrue();
});

it('moves holders to the fallback role when one is deleted', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesDelete]);

    $doomed = Role::query()->create(['name' => 'doomed', 'guard_name' => 'web', 'is_system' => false]);
    $survivor = Role::query()->create(['name' => 'survivor', 'guard_name' => 'web', 'is_system' => false]);

    $holder = User::factory()->create()->assignRole($doomed);

    $this->actingAs($user)
        ->delete(route('admin.roles.destroy', $doomed), ['fallback' => 'survivor']);

    expect(Role::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and($holder->refresh()->hasRole('survivor'))->toBeTrue();
});

it('refuses to strand holders when no fallback is named', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesDelete]);

    $doomed = Role::query()->create(['name' => 'doomed', 'guard_name' => 'web', 'is_system' => false]);
    User::factory()->create()->assignRole($doomed);

    $this->actingAs($user)
        ->delete(route('admin.roles.destroy', $doomed))
        ->assertSessionHasErrors('fallback');

    expect(Role::query()->whereKey($doomed->id)->exists())->toBeTrue();
});

it('assigns roles to a user', function () {
    $user = userHolding([
        Permission::RolesView,
        Permission::RolesAssign,
        Permission::VisitsViewOwn,
    ]);

    $target = User::factory()->create();

    $sales = Role::query()->create(['name' => 'sales', 'guard_name' => 'web', 'is_system' => false]);
    $sales->syncPermissions([Permission::VisitsViewOwn->value]);

    $this->actingAs($user)
        ->patch(route('admin.users.roles.update', $target), ['roles' => ['sales']]);

    expect($target->refresh()->hasRole('sales'))->toBeTrue();
});

/**
 * The same ceiling as creating a role, read one level up. Otherwise assigning
 * becomes the way around the grant check.
 */
it('refuses to assign a role holding permissions the assigner lacks', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesAssign]);

    $target = User::factory()->create();

    $powerful = Role::query()->create(['name' => 'powerful', 'guard_name' => 'web', 'is_system' => false]);
    $powerful->syncPermissions([Permission::CustomersDelete->value]);

    $this->actingAs($user)
        ->patch(route('admin.users.roles.update', $target), ['roles' => ['powerful']])
        ->assertSessionHasErrors('roles');

    expect($target->refresh()->hasRole('powerful'))->toBeFalse();
});

it('refuses to let a user change their own roles', function () {
    $user = userHolding([Permission::RolesView, Permission::RolesAssign]);

    $this->actingAs($user)
        ->patch(route('admin.users.roles.update', $user), ['roles' => []])
        ->assertForbidden();
});

it('lets a super admin through every check without holding a permission row', function () {
    $role = Role::query()->create([
        'name' => Role::SUPER_ADMIN,
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $user = User::factory()->create()->assignRole($role);

    expect($role->permissions)->toBeEmpty();

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertOk();
});
