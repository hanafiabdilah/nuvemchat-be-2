<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The dashboard API had no ceiling at all: any client holding a session could
 * call it as fast as it liked. On 5 Nov 2026 one session fired ~50 requests in
 * a single second, every five minutes, at a server with ten PHP workers. The
 * platform now decides what one person may cost it, whoever is spending it.
 */
// Frozen clock: a one-second window must not roll over halfway through a test
// on a slow machine and let the request that should be refused through.
beforeEach(function () {
    Http::fake();
    $this->freezeTime();
});

function rateLimitedOwner(): User
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    return $owner->fresh();
}

test('a burst over the per-second ceiling is refused with a code the dashboard can retry', function () {
    config(['api_limits.dashboard.per_second' => 3, 'api_limits.dashboard.per_minute' => 0]);
    Sanctum::actingAs(rateLimitedOwner());

    foreach (range(1, 3) as $_) {
        $this->getJson('/api/tags')->assertOk();
    }

    $refused = $this->getJson('/api/tags')->assertStatus(429);

    expect($refused->json('code'))->toBe('rate_limited')
        ->and($refused->headers->get('Retry-After'))->not->toBeNull();
});

test('each person has a budget of their own', function () {
    config(['api_limits.dashboard.per_second' => 2, 'api_limits.dashboard.per_minute' => 0]);
    $first = rateLimitedOwner();
    $second = rateLimitedOwner();

    Sanctum::actingAs($first);
    $this->getJson('/api/tags')->assertOk();
    $this->getJson('/api/tags')->assertOk();
    $this->getJson('/api/tags')->assertStatus(429);

    Sanctum::actingAs($second);
    $this->getJson('/api/tags')->assertOk();
});

test('the per-minute ceiling holds on its own when the burst limit is off', function () {
    config(['api_limits.dashboard.per_second' => 0, 'api_limits.dashboard.per_minute' => 2]);
    Sanctum::actingAs(rateLimitedOwner());

    $this->getJson('/api/tags')->assertOk();
    $this->getJson('/api/tags')->assertOk();

    expect($this->getJson('/api/tags')->assertStatus(429)->json('code'))->toBe('rate_limited');
});

test('zero switches the ceiling off', function () {
    config(['api_limits.dashboard.per_second' => 0, 'api_limits.dashboard.per_minute' => 0]);
    Sanctum::actingAs(rateLimitedOwner());

    foreach (range(1, 30) as $_) {
        $this->getJson('/api/tags')->assertOk();
    }
});

test('reaching the ceiling is logged once, not once per refused request', function () {
    config(['api_limits.dashboard.per_second' => 1, 'api_limits.dashboard.per_minute' => 0]);
    Log::spy();
    Sanctum::actingAs($owner = rateLimitedOwner());

    $this->getJson('/api/tags')->assertOk();
    $this->getJson('/api/tags')->assertStatus(429);
    $this->getJson('/api/tags')->assertStatus(429);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'Dashboard API rate limit reached'
            && $context['user_id'] === $owner->id
            && $context['path'] === 'api/tags')
        ->once();
});

afterEach(fn () => RateLimiter::clear('dashboard'));
