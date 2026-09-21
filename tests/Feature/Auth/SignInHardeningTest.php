<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/**
 * Both sign-in endpoints accepted an unlimited number of guesses, left nothing
 * in the audit trail when one failed, and — on the dashboard — handed out a
 * token without ever looking at whether the account had a second factor.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('sign-in:127.0.0.1');
});

function platformAdmin(array $attributes = []): Admin
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'platform-admin', 'guard_name' => 'web'],
        ['is_platform' => true],
    );

    $admin = Admin::create(array_merge([
        'name' => 'Ops',
        'email' => 'ops-'.uniqid().'@pingly.test',
        'password' => 'correct-horse-battery',
    ], $attributes));

    $admin->assignRole($role);

    return $admin;
}

function tenantOwner(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => 'owner-'.uniqid().'@example.test',
        'password' => Hash::make('correct-horse-battery'),
    ], $attributes));

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user;
}

/** A code the authenticator would be showing right now. */
function totpFor(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

// ── Throttling ──

it('stops guessing at a Back Office password after five tries', function () {
    $admin = platformAdmin();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertStatus(401);
    }

    // The sixth is refused without the password even being checked.
    $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonValidationErrors('email');

    // And the lockout is not a way past the password: the right one is refused
    // too while the counter is spent.
    $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'correct-horse-battery'])
        ->assertStatus(429);
});

it('stops guessing at a dashboard password too', function () {
    $user = tenantOwner();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401);
    }

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(429);
});

it('does not spend the budget on people who sign in successfully', function () {
    $user = tenantOwner();

    // Four wrong, then a right one, then four wrong again. A counter that
    // measured requests instead of failures would have locked this out.
    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonStructure(['access_token']);

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
    }
});

it('writes a failed Back Office sign-in to the audit trail', function () {
    $admin = platformAdmin();

    $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
        ->assertStatus(401);

    expect(AuditLog::where('action', 'auth.login_failed')->count())->toBe(1);
});

it('challenges a dashboard account that has a second factor, instead of handing out a token', function () {
    // This is the bypass the audit found: /api/auth/login issued a token
    // without ever reading two_factor_confirmed_at, so an account that had
    // turned a second factor on was still reachable with the password alone.
    // Written the way Fortify writes it — encrypted secret, recovery codes in
    // the clear inside the blob — so this exercises the real storage format
    // rather than one only the test understands.
    $user = tenantOwner();
    $user->forceFill([
        'two_factor_secret' => encrypt('ABCDEFGHIJKLMNOP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaaa-bbbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertOk();

    expect($response->json('two_factor'))->toBeTrue()
        ->and($response->json('access_token'))->toBeNull();

    $this->postJson('/api/auth/two-factor-challenge', [
        'challenge' => $response->json('challenge'),
        'code' => totpFor('ABCDEFGHIJKLMNOP'),
    ])->assertOk()->assertJsonStructure(['access_token']);
});

// ── Second factor, Back Office ──

it('does not hand out a token when a second factor is enrolled', function () {
    $secret = app(TwoFactor::class)->newSecret();
    $admin = platformAdmin();
    $admin->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->assertOk();

    // The password was right, and it bought a challenge rather than access.
    expect($response->json('two_factor'))->toBeTrue()
        ->and($response->json('challenge'))->toBeString()
        ->and($response->json('access_token'))->toBeNull();

    // A wrong code gets nothing…
    $this->postJson('/api/admin/auth/two-factor-challenge', [
        'challenge' => $response->json('challenge'),
        'code' => '000000',
    ])->assertStatus(422);

    // …and the right one completes the sign-in. The ticket surviving the wrong
    // code is deliberate: mistyping a digit must not cost the password again.
    $this->postJson('/api/admin/auth/two-factor-challenge', [
        'challenge' => $response->json('challenge'),
        'code' => totpFor($secret),
    ])->assertOk()->assertJsonStructure(['access_token']);
});

it('spends a recovery code exactly once', function () {
    $twoFactor = app(TwoFactor::class);
    $secret = $twoFactor->newSecret();
    $codes = $twoFactor->newRecoveryCodes();

    $admin = platformAdmin();
    $admin->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => $codes['hashed'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $challenge = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->json('challenge');

    $recovery = $codes['plain'][0];

    $this->postJson('/api/admin/auth/two-factor-challenge', [
        'challenge' => $challenge,
        'recovery_code' => $recovery,
    ])->assertOk()->assertJsonStructure(['access_token']);

    expect($admin->fresh()->two_factor_recovery_codes)->toHaveCount(TwoFactor::RECOVERY_CODES - 1);

    // The same code again is just a wrong code. A recovery code that survived
    // being used is a password that never expires.
    $second = $this->postJson('/api/admin/auth/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ])->json('challenge');

    $this->postJson('/api/admin/auth/two-factor-challenge', [
        'challenge' => $second,
        'recovery_code' => $recovery,
    ])->assertStatus(422);
});

it('keeps recovery codes out of the database in readable form', function () {
    $twoFactor = app(TwoFactor::class);
    $codes = $twoFactor->newRecoveryCodes();

    $admin = platformAdmin();
    $admin->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();

    $stored = json_encode($admin->fresh()->two_factor_recovery_codes);

    foreach ($codes['plain'] as $plain) {
        expect($stored)->not->toContain($plain);
    }
});

// ── Enrolment ──

it('will not turn a second factor on until a real code proves it works', function () {
    $admin = platformAdmin();
    Laravel\Sanctum\Sanctum::actingAs($admin, ['admin']);

    $start = $this->postJson('/api/admin/account/two-factor', [
        'current_password' => 'correct-horse-battery',
    ])->assertOk();

    $secret = $start->json('data.secret');

    // A secret exists, but the account still signs in with a password alone —
    // a QR that never scanned must not lock anybody out.
    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->postJson('/api/admin/account/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422);

    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

    $confirmed = $this->postJson('/api/admin/account/two-factor/confirm', ['code' => totpFor($secret)])
        ->assertOk();

    expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($confirmed->json('data.recovery_codes'))->toHaveCount(TwoFactor::RECOVERY_CODES);
});

it('asks for the password before adding or removing a factor', function () {
    $admin = platformAdmin();
    Laravel\Sanctum\Sanctum::actingAs($admin, ['admin']);

    // A stolen session must not be enough to enrol a factor of the thief's own
    // — that would lock the real operator out of their own account.
    $this->postJson('/api/admin/account/two-factor', ['current_password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    $this->deleteJson('/api/admin/account/two-factor', ['current_password' => 'nope'])
        ->assertStatus(422);
});

// ── Enforcement ──

it('keeps unenrolled admins out once enforcement is on, but leaves them a way in', function () {
    config()->set('services.admin.mfa_required', true);

    $admin = platformAdmin();
    Laravel\Sanctum\Sanctum::actingAs($admin, ['admin']);

    $this->getJson('/api/admin/stats')
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_required');

    // The door still opens from the side they are on: reading who they are,
    // enrolling, and signing out.
    $this->getJson('/api/admin/auth/me')->assertOk();
    $this->postJson('/api/admin/account/two-factor', ['current_password' => 'correct-horse-battery'])->assertOk();
});
