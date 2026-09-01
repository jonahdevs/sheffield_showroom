<?php

use App\Models\User;

test('the root sends a visitor to the sign-in screen', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/login');
});

# The root has no session check of its own; it leans on the `guest`
# middleware guarding /login, which is what this pins.
test('a signed-in visitor is carried on to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect(route('dashboard'));
});
