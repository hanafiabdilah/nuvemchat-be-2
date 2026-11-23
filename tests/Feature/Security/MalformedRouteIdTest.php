<?php

use App\Models\Flow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * A route id that is not a number answers 404 — it used to reach a controller
 * declaring `int $id`, fail with a TypeError and land in the error log as a
 * 500 with a stack trace (RejectMalformedRouteIds).
 */

function malformedIdOwner(): User
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->assignRole(Role::findOrCreate('owner', 'web'));
    $owner->givePermissionTo(
        Permission::findOrCreate('flows.view', 'web'),
        Permission::findOrCreate('flows.delete', 'web'),
    );

    return $owner->fresh();
}

test('a non-numeric id is a 404 and nothing is reported', function (string $method, string $uri) {
    Exceptions::fake();
    Sanctum::actingAs(malformedIdOwner());

    $this->json($method, $uri)->assertNotFound();

    Exceptions::assertNothingReported();
})->with([
    'flow delete' => ['DELETE', '/api/flows/undefined'],
    'flow show' => ['GET', '/api/flows/null'],
    'conversation' => ['GET', '/api/conversations/abc'],
    'negative' => ['GET', '/api/conversations/-1'],
    'too long for an int' => ['GET', '/api/conversations/99999999999999999999999'],
]);

test('a numeric id still reaches the controller', function () {
    $owner = malformedIdOwner();
    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Boas-vindas']);

    Sanctum::actingAs($owner);

    $this->getJson('/api/flows/'.$flow->id)->assertOk()->assertJsonPath('data.name', 'Boas-vindas');
    // Numeric but unknown: the controller's own 404, as before.
    $this->getJson('/api/flows/'.($flow->id + 1000))->assertNotFound();

    $this->deleteJson('/api/flows/'.$flow->id)->assertOk();
    expect(Flow::find($flow->id))->toBeNull();
});

test('signing in still comes first', function () {
    $this->deleteJson('/api/flows/undefined')->assertUnauthorized();
});
