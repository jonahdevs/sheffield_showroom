<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a user a role holding exactly the permissions named.
 *
 * @param  array<int, Permission>  $permissions
 */
function staffHolding(array $permissions, string $role = 'staff-tester'): User
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

// -----------------------------------------------------------------------------
// Reaching the screens at all
// -----------------------------------------------------------------------------

it('refuses the new user form without users.create', function () {
    $this->actingAs(staffHolding([Permission::UsersUpdate]))
        ->get(route('admin.users.create'))
        ->assertForbidden();
});

it('refuses the edit screen without users.update', function () {
    $actor = staffHolding([Permission::UsersViewAny]);

    $this->actingAs($actor)
        ->get(route('admin.users.edit', User::factory()->create()))
        ->assertForbidden();
});

it('opens the edit screen with users.update', function () {
    $actor = staffHolding([Permission::UsersUpdate]);

    $this->actingAs($actor)
        ->get(route('admin.users.edit', User::factory()->create()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/users/Form'));
});

it('refuses the direct permission write without users.permissions', function () {
    $actor = staffHolding([Permission::UsersUpdate, Permission::VisitsViewAny]);

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', User::factory()->create()), [
            'permissions' => [Permission::VisitsViewAny->value],
        ])
        ->assertForbidden();
});

it('refuses the password write without users.update', function () {
    $this->actingAs(staffHolding([Permission::UsersViewAny]))
        ->put(route('admin.users.password.update', User::factory()->create()), [
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
        ])
        ->assertForbidden();
});

it('hides the people panel from a role editor who cannot view users', function () {
    $this->actingAs(staffHolding([Permission::RolesView]))
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('holders', null));
});

// -----------------------------------------------------------------------------
// Creating an account
// -----------------------------------------------------------------------------

it('creates a user and puts them in a role', function () {
    $actor = staffHolding([
        Permission::UsersCreate,
        Permission::RolesAssign,
        Permission::VisitsViewOwn,
    ]);

    $sales = Role::query()->create(['name' => 'sales', 'guard_name' => 'web', 'is_system' => false]);
    $sales->syncPermissions([Permission::VisitsViewOwn->value]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Achieng Odhiambo',
            'email' => 'achieng@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => ['sales'],
        ])
        ->assertRedirect(route('admin.roles.index'));

    $created = User::query()->where('email', 'achieng@sheffieldafrica.com')->sole();

    expect($created->hasRole('sales'))->toBeTrue()
        ->and($created->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Sheffield-Showroom-1', $created->password))->toBeTrue();
});

/**
 * Creating an account would otherwise be the shortest way past the ceiling
 * assigning one enforces.
 */
it('refuses to create a user in a role beyond the creator', function () {
    $actor = staffHolding([Permission::UsersCreate, Permission::RolesAssign]);

    $powerful = Role::query()->create(['name' => 'powerful', 'guard_name' => 'web', 'is_system' => false]);
    $powerful->syncPermissions([Permission::CustomersDelete->value]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => ['powerful'],
        ])
        ->assertSessionHasErrors('roles');

    expect(User::query()->where('email', 'sneaky@sheffieldafrica.com')->exists())->toBeFalse();
});

it('pins a direct permission to a user as the account is created', function () {
    $actor = staffHolding([
        Permission::UsersCreate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Achieng Odhiambo',
            'email' => 'achieng@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => [],
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertRedirect(route('admin.roles.index'));

    $created = User::query()->where('email', 'achieng@sheffieldafrica.com')->sole();

    expect($created->getDirectPermissions()->pluck('name')->all())
        ->toBe([Permission::VisitsExport->value]);
});

it('refuses to pin a permission the creator does not hold', function () {
    $actor = staffHolding([Permission::UsersCreate, Permission::UsersPermissions]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => [],
            'permissions' => [Permission::CustomersDelete->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect(User::query()->where('email', 'sneaky@sheffieldafrica.com')->exists())->toBeFalse();
});

it('refuses to pin a permission without users.permissions', function () {
    $actor = staffHolding([Permission::UsersCreate, Permission::VisitsExport]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => [],
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect(User::query()->where('email', 'sneaky@sheffieldafrica.com')->exists())->toBeFalse();
});

/**
 * The disjoint rule, read at the one moment both halves move together: the
 * role and the pin are chosen on the same form, so the overlap has to be
 * caught before the account exists rather than after.
 */
it('refuses to pin a permission a role on the same form already grants', function () {
    $actor = staffHolding([
        Permission::UsersCreate,
        Permission::UsersPermissions,
        Permission::RolesAssign,
        Permission::VisitsExport,
    ]);

    $exporter = Role::query()->create(['name' => 'exporter', 'guard_name' => 'web', 'is_system' => false]);
    $exporter->syncPermissions([Permission::VisitsExport->value]);

    $this->actingAs($actor)
        ->post(route('admin.users.store'), [
            'name' => 'Doubled',
            'email' => 'doubled@sheffieldafrica.com',
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
            'roles' => ['exporter'],
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect(User::query()->where('email', 'doubled@sheffieldafrica.com')->exists())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Staffing an account from its own screen
// -----------------------------------------------------------------------------

it('offers the roles and what each of them brings on the edit screen', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::RolesAssign,
        Permission::VisitsExport,
    ]);

    $exporter = Role::query()->create(['name' => 'exporter', 'guard_name' => 'web', 'is_system' => false]);
    $exporter->syncPermissions([Permission::VisitsExport->value]);

    $this->actingAs($actor)
        ->get(route('admin.users.edit', User::factory()->create()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.assign_roles', true)
            ->where('role_grants.exporter', [Permission::VisitsExport->value]));
});

/**
 * The super admin holds every ability through `Gate::before` while its role may
 * hold no permission row, so a subset test against the database says it grants
 * nothing and anybody with `roles.assign` could hand it out.
 */
it('refuses to hand out the super admin role from below it', function () {
    $actor = staffHolding([Permission::UsersUpdate, Permission::RolesAssign]);

    $super = Role::query()->create([
        'name' => Role::SUPER_ADMIN,
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $subject = User::factory()->create();

    expect($super->permissions)->toBeEmpty();

    $this->actingAs($actor)
        ->patch(route('admin.users.roles.update', $subject), ['roles' => [Role::SUPER_ADMIN]])
        ->assertSessionHasErrors('roles');

    expect($subject->refresh()->hasRole(Role::SUPER_ADMIN))->toBeFalse();
});

it('sends a changed email back to unverified', function () {
    $actor = staffHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create();

    $this->actingAs($actor)
        ->patch(route('admin.users.update', $subject), [
            'name' => $subject->name,
            'email' => 'moved@sheffieldafrica.com',
        ])
        ->assertRedirect(route('admin.users.edit', $subject));

    expect($subject->refresh()->email_verified_at)->toBeNull();
});

// -----------------------------------------------------------------------------
// Permissions granted straight to an account
// -----------------------------------------------------------------------------

it('grants a permission directly to a user', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $subject = User::factory()->create();

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), [
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertRedirect(route('admin.users.edit', $subject));

    expect($subject->refresh()->getDirectPermissions()->pluck('name')->all())
        ->toBe([Permission::VisitsExport->value])
        ->and($subject->can(Permission::VisitsExport->value))->toBeTrue();
});

it('revokes a direct permission when it is left out of the set', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $subject = User::factory()->create();
    $subject->givePermissionTo(Permission::VisitsExport->value);

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), ['permissions' => []]);

    expect($subject->refresh()->getDirectPermissions())->toBeEmpty();
});

/**
 * The trap the whole feature has to avoid. A duplicate of what a role already
 * grants would survive that role being taken away, and nothing on the Roles
 * screen would say so.
 */
it('refuses to pin a permission the user already inherits from a role', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $exporter = Role::query()->create(['name' => 'exporter', 'guard_name' => 'web', 'is_system' => false]);
    $exporter->syncPermissions([Permission::VisitsExport->value]);

    $subject = User::factory()->create()->assignRole($exporter);

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), [
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect($subject->refresh()->getDirectPermissions())->toBeEmpty();
});

/**
 * The overlap has to be clearable, or the one form that can undo it would
 * refuse to submit. Only a new duplicate is refused.
 */
it('lets an existing duplicate be saved and cleared', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $exporter = Role::query()->create(['name' => 'exporter', 'guard_name' => 'web', 'is_system' => false]);
    $exporter->syncPermissions([Permission::VisitsExport->value]);

    /* The state a role definition change leaves behind: pinned first, then
       covered by a role afterwards. */
    $subject = User::factory()->create();
    $subject->givePermissionTo(Permission::VisitsExport->value);
    $subject->assignRole($exporter);

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), [
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), ['permissions' => []]);

    expect($subject->refresh()->getDirectPermissions())->toBeEmpty()
        ->and($subject->can(Permission::VisitsExport->value))->toBeTrue();
});

/**
 * The duplicate that would otherwise outlive the role behind it.
 */
it('drops a direct grant that a newly assigned role now covers', function () {
    $actor = staffHolding([Permission::RolesAssign, Permission::VisitsExport]);

    $exporter = Role::query()->create(['name' => 'exporter', 'guard_name' => 'web', 'is_system' => false]);
    $exporter->syncPermissions([Permission::VisitsExport->value]);

    $subject = User::factory()->create();
    $subject->givePermissionTo(Permission::VisitsExport->value);

    $this->actingAs($actor)
        ->patch(route('admin.users.roles.update', $subject), ['roles' => ['exporter']]);

    expect($subject->refresh()->getDirectPermissions())->toBeEmpty()
        ->and($subject->hasRole('exporter'))->toBeTrue();

    /* And the point of it: taking the role away really does take the
       capability away. */
    $subject->syncRoles([]);

    expect($subject->refresh()->can(Permission::VisitsExport->value))->toBeFalse();
});

/**
 * The obvious hole: an administrator handing themselves, through somebody
 * else's account or their own, a capability their own account never had.
 */
it('refuses to grant a permission the granter does not hold', function () {
    $actor = staffHolding([Permission::UsersUpdate, Permission::UsersPermissions]);

    $subject = User::factory()->create();

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $subject), [
            'permissions' => [Permission::CustomersDelete->value],
        ])
        ->assertSessionHasErrors('permissions');

    expect($subject->refresh()->getDirectPermissions())->toBeEmpty();
});

it('refuses to let a user edit their own direct permissions', function () {
    $actor = staffHolding([
        Permission::UsersUpdate,
        Permission::UsersPermissions,
        Permission::VisitsExport,
    ]);

    $this->actingAs($actor)
        ->patch(route('admin.users.permissions.update', $actor), [
            'permissions' => [Permission::VisitsExport->value],
        ])
        ->assertForbidden();

    expect($actor->refresh()->getDirectPermissions())->toBeEmpty();
});

// -----------------------------------------------------------------------------
// Reaching past your own ceiling
// -----------------------------------------------------------------------------

it('refuses to edit an account that can do more than the editor', function () {
    $actor = staffHolding([Permission::UsersUpdate]);

    $powerful = Role::query()->create(['name' => 'powerful', 'guard_name' => 'web', 'is_system' => false]);
    $powerful->syncPermissions([Permission::CustomersDelete->value]);

    $subject = User::factory()->create()->assignRole($powerful);

    $this->actingAs($actor)
        ->get(route('admin.users.edit', $subject))
        ->assertForbidden();

    $this->actingAs($actor)
        ->patch(route('admin.users.update', $subject), [
            'name' => 'Renamed',
            'email' => 'renamed@sheffieldafrica.com',
        ])
        ->assertForbidden();
});

/**
 * The super admin holds every ability through `Gate::before` while its role may
 * hold no permission row at all, so a subset test against the database says it
 * can do nothing. Taking that account over is the whole prize; it is named
 * rather than derived.
 */
it('refuses to touch a super admin whose role holds no permission rows', function () {
    $actor = staffHolding([Permission::UsersUpdate]);

    $super = Role::query()->create([
        'name' => Role::SUPER_ADMIN,
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    $subject = User::factory()->create()->assignRole($super);

    expect($super->permissions)->toBeEmpty();

    $this->actingAs($actor)
        ->put(route('admin.users.password.update', $subject), [
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
        ])
        ->assertForbidden();

    expect(Hash::check('Sheffield-Showroom-1', $subject->refresh()->password))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Setting a password
// -----------------------------------------------------------------------------

it('sets a password and ends every session the account had open', function () {
    /* The suite runs on the array session driver, where there is nothing to
       delete. Pointed at the database driver the write is real, which is the
       half of the revocation worth asserting. */
    config(['session.driver' => 'database']);

    $actor = staffHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create(['remember_token' => 'still-signed-in']);

    DB::table('sessions')->insert([
        'id' => 'session-under-test',
        'user_id' => $subject->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'phpunit',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($actor)
        ->put(route('admin.users.password.update', $subject), [
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
        ])
        ->assertRedirect(route('admin.users.edit', $subject));

    $subject->refresh();

    expect(Hash::check('Sheffield-Showroom-1', $subject->password))->toBeTrue()
        ->and($subject->remember_token)->not->toBe('still-signed-in')
        ->and(DB::table('sessions')->where('user_id', $subject->id)->exists())->toBeFalse();
});

it('refuses a password that is not confirmed', function () {
    $actor = staffHolding([Permission::UsersUpdate]);
    $subject = User::factory()->create();

    $this->actingAs($actor)
        ->put(route('admin.users.password.update', $subject), [
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'something-else',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('Sheffield-Showroom-1', $subject->refresh()->password))->toBeFalse();
});

/**
 * Your own password is changed from Settings, where the current one has to be
 * typed first. Skipping that here would turn a borrowed unlocked laptop into a
 * takeover.
 */
it('refuses to let a user set their own password from here', function () {
    $actor = staffHolding([Permission::UsersUpdate]);

    $this->actingAs($actor)
        ->put(route('admin.users.password.update', $actor), [
            'password' => 'Sheffield-Showroom-1',
            'password_confirmation' => 'Sheffield-Showroom-1',
        ])
        ->assertForbidden();

    expect(Hash::check('Sheffield-Showroom-1', $actor->refresh()->password))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Making a direct grant visible
// -----------------------------------------------------------------------------

it('names direct holders on the permissions screen', function () {
    $actor = staffHolding([Permission::RolesView, Permission::UsersViewAny]);

    $subject = User::factory()->create(['name' => 'Pinned Person']);
    $subject->givePermissionTo(Permission::VisitsExport->value);

    $this->actingAs($actor)
        ->get(route('admin.permissions.index', ['search' => Permission::VisitsExport->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.data.0.users', ['Pinned Person']));
});

it('counts direct grants on the people panel', function () {
    $actor = staffHolding([Permission::RolesView, Permission::UsersViewAny]);

    $subject = User::factory()->create();
    $subject->givePermissionTo(Permission::VisitsExport->value);

    $response = $this->actingAs($actor)
        ->get(route('admin.roles.index', ['search' => $subject->email]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('holders.data.0.direct_permissions', 1)
        ->where('holders.data.0.is_manageable', false));
});
