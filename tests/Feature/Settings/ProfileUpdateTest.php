<?php

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * A direct grant rather than a role: these tests are about the permission,
 * not about who granted it.
 */
function accountGranted(Permission $permission): User
{
    Spatie\Permission\Models\Permission::findOrCreate($permission->value, 'web');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return tap(User::factory()->create())->givePermissionTo($permission->value);
}

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->name)->toBe('Test User');
});

# Dropped rather than refused: the field is disabled on the form, so an
# address arriving under that name is a stale page, not an attack.
test('the email address cannot be changed without profile.email.update', function () {
    $user = User::factory()->create(['email' => 'mine@sheffieldafrica.com']);

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'taken-over@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->email)->toBe('mine@sheffieldafrica.com')
        ->and($user->name)->toBe('Test User')
        # Not sent back to unverified over a change that never happened.
        ->and($user->email_verified_at)->not->toBeNull();
});

test('the email address can be changed with profile.email.update', function () {
    $user = accountGranted(Permission::ProfileEmailUpdate);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'moved@sheffieldafrica.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->email)->toBe('moved@sheffieldafrica.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('refuses an address another account already holds', function () {
    $user = accountGranted(Permission::ProfileEmailUpdate);

    User::factory()->create(['email' => 'taken@sheffieldafrica.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'taken@sheffieldafrica.com',
        ])
        ->assertSessionHasErrors('email');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
