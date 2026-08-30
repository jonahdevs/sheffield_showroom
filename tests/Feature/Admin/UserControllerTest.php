<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function accountHolding(array $permissions, string $role = 'tester'): User
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

/** A role holding exactly the permissions named. */
function roleGranting(string $name, array $permissions = []): Role
{
    $role = Role::query()->create([
        'name' => $name,
        'guard_name' => 'web',
        'is_system' => false,
    ]);

    $role->syncPermissions(array_map(fn (Permission $case) => $case->value, $permissions));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

it('refuses the account form to a user without users.create', function () {
    $admin = accountHolding([Permission::RolesView]);

    $this->actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertForbidden();
});

it('opens the account form for a user holding users.create', function () {
    $admin = accountHolding([Permission::UsersCreate]);

    $this->actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertOk();
});

it('creates an account with the roles ticked', function () {
    $admin = accountHolding([
        Permission::UsersCreate,
        Permission::RolesAssign,
        Permission::VisitsCreate,
    ]);

    roleGranting('floor', [Permission::VisitsCreate]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Achieng Odhiambo',
            'email' => 'achieng@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'roles' => ['floor'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.roles.index'));

    $created = User::query()->where('email', 'achieng@example.com')->firstOrFail();

    expect($created->name)->toBe('Achieng Odhiambo');
    expect($created->hasRole('floor'))->toBeTrue();
    expect(Hash::check('correct-horse-battery', $created->password))->toBeTrue();
});

it('refuses to hand out a role holding permissions the creator lacks', function () {
    $admin = accountHolding([Permission::UsersCreate, Permission::RolesAssign]);

    roleGranting('floor-boss', [Permission::VisitsDelete]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Achieng Odhiambo',
            'email' => 'achieng@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'roles' => ['floor-boss'],
        ])
        ->assertSessionHasErrors('roles');

    expect(User::query()->where('email', 'achieng@example.com')->exists())->toBeFalse();
});

it('changes the email address an account signs in with', function () {
    $admin = accountHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => 'Renamed Person',
            'email' => 'new@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.roles.index'));

    $subject->refresh();

    expect($subject->name)->toBe('Renamed Person');
    expect($subject->email)->toBe('new@example.com');
});

it('leaves the password alone when the form is submitted without one', function () {
    $admin = accountHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create();
    $before = $subject->password;

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $subject->email,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($subject->refresh()->password)->toBe($before);
});

it('replaces the password when one is typed', function () {
    $admin = accountHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $subject->email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('correct-horse-battery', $subject->refresh()->password))->toBeTrue();
});

it('refuses an email address another account already holds', function () {
    $admin = accountHolding([Permission::UsersUpdate]);
    $taken = User::factory()->create(['email' => 'taken@example.com']);
    $subject = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $taken->email,
        ])
        ->assertSessionHasErrors('email');

    expect($subject->refresh()->email)->toBe('mine@example.com');
});

it('ignores roles sent by somebody who may not assign them', function () {
    $admin = accountHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create();

    roleGranting('floor');

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $subject->email,
            'roles' => ['floor'],
        ])
        ->assertSessionHasNoErrors();

    expect($subject->refresh()->hasRole('floor'))->toBeFalse();
});

it('refuses to let a user re-role themselves through the account form', function () {
    $admin = accountHolding([Permission::UsersUpdate, Permission::RolesAssign]);

    roleGranting('floor');

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'roles' => ['floor'],
        ])
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->hasRole('floor'))->toBeFalse();
});

it('grants a permission straight onto an account', function () {
    $admin = accountHolding([
        Permission::UsersUpdate,
        Permission::RolesAssign,
        Permission::ProductsViewAny,
    ]);

    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $subject->email,
            'roles' => [],
            'permissions' => [Permission::ProductsViewAny->value],
        ])
        ->assertSessionHasNoErrors();

    $subject->refresh();

    expect($subject->hasDirectPermission(Permission::ProductsViewAny->value))->toBeTrue();
});

it('refuses to grant a permission the grantor does not hold', function () {
    $admin = accountHolding([Permission::UsersUpdate, Permission::RolesAssign]);
    $subject = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => $subject->email,
            'roles' => [],
            'permissions' => [Permission::VisitsDelete->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect($subject->refresh()->hasDirectPermission(Permission::VisitsDelete->value))->toBeFalse();
});

it('leaves roles and permissions alone when the request carries neither', function () {
    $admin = accountHolding([Permission::UsersUpdate, Permission::RolesAssign]);

    $floor = roleGranting('floor');
    $subject = User::factory()->create()->assignRole($floor);

    $this->actingAs($admin)
        ->patch(route('admin.users.update', $subject), [
            'name' => 'Renamed Person',
            'email' => $subject->email,
        ])
        ->assertSessionHasNoErrors();

    expect($subject->refresh()->hasRole('floor'))->toBeTrue();
});

it('offers the account links on the roles screen only to those who may use them', function () {
    $admin = accountHolding([
        Permission::RolesView,
        Permission::UsersCreate,
        Permission::UsersUpdate,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.create_user', true)
            ->where('can.update_user', true));

    $viewer = accountHolding([Permission::RolesView], 'watcher');

    $this->actingAs($viewer)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.create_user', false)
            ->where('can.update_user', false));
});
