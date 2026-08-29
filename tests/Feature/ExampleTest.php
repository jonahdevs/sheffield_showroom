<?php

use App\Models\User;

test('the root sends a visitor to the sign-in screen', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/login');
});

/* The root deliberately does not check for a session of its own; it leans on
   the `guest` middleware already guarding /login. That only holds while the
   middleware keeps sending people to the dashboard, so pin it here. */
test('a signed-in visitor is carried on to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect(route('dashboard'));
});
