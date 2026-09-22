<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * ⚠️ Registration assigns the `owner` role (App\Actions\Fortify\CreateNewUser),
 * and that role is created by a seeder rather than a migration — so it exists
 * on every real install and on none of these. Without this line the request
 * throws RoleDoesNotExist and the failure surfaces as "the user is not
 * authenticated", which says nothing about roles and sent this test's diagnosis
 * down the wrong path more than once.
 *
 * The same `Role::findOrCreate('owner', 'web')` that the rest of the suite uses.
 */
beforeEach(function () {
    \Spatie\Permission\Models\Role::findOrCreate('owner', 'web');
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
