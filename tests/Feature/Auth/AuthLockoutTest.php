<?php

use App\Models\Admin;
use App\Models\Otp;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationConfig;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * The limits on guessing: a verification code lives five minutes and takes
 * three wrong tries; the second code spent that way blocks verification for an
 * hour. Five wrong passwords block sign-in for thirty minutes. Both blocks say
 * how long they last and who to contact. And a temp-mail address cannot sign up.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Setting::set(NotificationConfig::KEY_ENABLED, '1');
    config(['app.support_email' => 'contato@pingly.com.br']);
});

function lockoutUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana',
        'email' => 'ana-'.uniqid().'@example.com',
        'password' => Hash::make('correct-horse-battery'),
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => null,
    ], $overrides));

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function latestCode(User $user, string $purpose): string
{
    return Otp::where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->firstOrFail()->code;
}

/** A six-digit code that is certainly not the right one. */
function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

// ── Lifetime ──

it('gives every code five minutes', function () {
    $user = lockoutUser();
    $otp = app(OtpService::class)->request($user);

    expect((int) round(now()->diffInMinutes($otp->expires_at)))->toBe(5);

    $this->travel(6)->minutes();

    $this->actingAs($user)->postJson('/api/auth/otp/verify', ['code' => $otp->code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'otp_expired');
});

// ── Registration: three tries, then a new code, then an hour ──

it('spends a signup code after three wrong tries, and blocks after the second one', function () {
    $user = lockoutUser();
    $this->actingAs($user);

    $this->postJson('/api/auth/otp/send')->assertOk();
    $wrong = wrongCode(latestCode($user, OtpService::PURPOSE_WHATSAPP));

    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])
        ->assertStatus(422)->assertJsonPath('code', 'otp_incorrect')->assertJsonPath('attempts_left', 2);
    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])
        ->assertStatus(422)->assertJsonPath('attempts_left', 1);
    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])
        ->assertStatus(422)->assertJsonPath('code', 'otp_attempts_exhausted')->assertJsonValidationErrors('code');

    // Spent means spent: not even the right code gets through any more.
    $this->postJson('/api/auth/otp/verify', ['code' => latestCode($user, OtpService::PURPOSE_WHATSAPP)])
        ->assertStatus(422)->assertJsonPath('code', 'otp_attempts_exhausted');

    // A new code starts a fresh three…
    $this->travel(61)->seconds();
    $this->postJson('/api/auth/otp/send')->assertOk();
    $wrong = wrongCode(latestCode($user, OtpService::PURPOSE_WHATSAPP));

    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])->assertJsonPath('attempts_left', 2);
    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])->assertJsonPath('attempts_left', 1);

    // …and spending it as well blocks verification for an hour.
    $this->postJson('/api/auth/otp/verify', ['code' => $wrong])
        ->assertStatus(429)
        ->assertJsonPath('code', 'otp_blocked')
        ->assertJsonPath('retry_after', 3600)
        ->assertJsonPath('support_email', 'contato@pingly.com.br')
        ->assertHeader('Retry-After', '3600');

    // Blocked includes asking for another code, and the screen is told so on load.
    $this->travel(61)->seconds();
    $this->postJson('/api/auth/otp/send')->assertStatus(429)->assertJsonPath('code', 'otp_blocked');
    expect($this->getJson('/api/auth/otp/status')->json('blocked_for'))->toBeGreaterThan(3400);

    // An hour later it lifts by itself.
    $this->travel(61)->minutes();
    $this->postJson('/api/auth/otp/send')->assertOk();
    $this->postJson('/api/auth/otp/verify', ['code' => latestCode($user, OtpService::PURPOSE_WHATSAPP)])
        ->assertOk()->assertJsonPath('verified', true);
});

it('forgives the spent code once the right one is entered', function () {
    $user = lockoutUser();
    $this->actingAs($user);

    $this->postJson('/api/auth/otp/send')->assertOk();
    $wrong = wrongCode(latestCode($user, OtpService::PURPOSE_WHATSAPP));
    foreach (range(1, 3) as $try) {
        $this->postJson('/api/auth/otp/verify', ['code' => $wrong]);
    }

    $this->travel(61)->seconds();
    $this->postJson('/api/auth/otp/send')->assertOk();
    $this->postJson('/api/auth/otp/verify', ['code' => wrongCode(latestCode($user, OtpService::PURPOSE_WHATSAPP))])
        ->assertJsonPath('attempts_left', 2);
    $this->postJson('/api/auth/otp/verify', ['code' => latestCode($user, OtpService::PURPOSE_WHATSAPP)])->assertOk();

    expect(app(OtpService::class)->blockedFor($user))->toBe(0);
});

// ── Password recovery: the same rules, and the same answers with or without an account ──

it('runs password recovery through the same three-then-block sequence', function () {
    $user = lockoutUser(['whatsapp_verified_at' => now()]);

    $sequence = function (string $email, ?User $owner) {
        $codes = [];

        foreach ([1, 2] as $round) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email])->assertOk();
            $wrong = $owner ? wrongCode(latestCode($owner, OtpService::PURPOSE_PASSWORD_RESET)) : '000000';

            foreach (range(1, 3) as $try) {
                $codes[] = $this->postJson('/api/auth/password/verify', ['email' => $email, 'code' => $wrong])->json('code');
            }

            $this->travel(61)->seconds();
        }

        $codes[] = $this->postJson('/api/auth/password/forgot', ['email' => $email])->json('code');

        return $codes;
    };

    $known = $sequence($user->email, $user);
    $unknown = $sequence('nobody-'.uniqid().'@example.com', null);

    expect($known)->toBe([
        'otp_incorrect', 'otp_incorrect', 'otp_attempts_exhausted',
        'otp_incorrect', 'otp_incorrect', 'otp_blocked',
        'otp_blocked',
    ]);

    // An address with no account answers step for step the same — the limits
    // must not become a way to tell which addresses are registered.
    expect($unknown)->toBe($known);
});

it('keeps the password unchanged while recovery is blocked', function () {
    $user = lockoutUser(['whatsapp_verified_at' => now()]);

    foreach ([1, 2] as $round) {
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $wrong = wrongCode(latestCode($user, OtpService::PURPOSE_PASSWORD_RESET));
        foreach (range(1, 3) as $try) {
            $this->postJson('/api/auth/password/verify', ['email' => $user->email, 'code' => $wrong]);
        }
        $this->travel(61)->seconds();
    }

    $this->postJson('/api/auth/password/reset', [
        'email' => $user->email,
        'code' => latestCode($user, OtpService::PURPOSE_PASSWORD_RESET),
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertStatus(429)->assertJsonPath('code', 'otp_blocked');

    expect(Hash::check('correct-horse-battery', $user->fresh()->password))->toBeTrue();
});

// ── Sign-in: five wrong passwords, thirty minutes ──

it('blocks dashboard sign-in for thirty minutes on the fifth wrong password', function () {
    $user = lockoutUser();

    foreach ([4, 3, 2, 1] as $left) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'login_failed')
            ->assertJsonPath('attempts_left', $left);
    }

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'login_blocked')
        ->assertJsonPath('retry_after', 1800)
        ->assertJsonPath('support_email', 'contato@pingly.com.br')
        ->assertJsonValidationErrors('email');

    // The right password does not get through a block.
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertStatus(429);

    // Thirty minutes from the failure that caused it — not from the first one.
    $this->travel(29)->minutes();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertStatus(429);

    $this->travel(2)->minutes();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertOk()->assertJsonStructure(['access_token']);
});

it('counts the thirty minutes from the fifth failure, however slowly the five came', function () {
    $user = lockoutUser();

    foreach (range(1, 4) as $try) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $this->travel(6)->minutes();
    }

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);

    $this->travel(20)->minutes();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertStatus(429);
});

it('does not send Back Office operators to support', function () {
    $role = Role::query()->firstOrCreate(['name' => 'platform-admin', 'guard_name' => 'web'], ['is_platform' => true]);
    $admin = Admin::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@pingly.test', 'password' => 'correct-horse-battery']);
    $admin->assignRole($role);

    foreach (range(1, 5) as $try) {
        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertStatus(401);
    }

    $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'login_blocked')
        ->assertJsonMissingPath('support_email');
});

// ── Temp mail ──

it('refuses a signup with a temp-mail address', function (string $email) {
    $this->postJson('/api/auth/register', [
        'name' => 'Teste',
        'email' => $email,
        'password' => 'correct-horse-battery-1',
        'password_confirmation' => 'correct-horse-battery-1',
        'whatsapp_number' => '+5511999990000',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'listed' => 'someone@mailinator.com',
    'subdomain of a listed one' => 'someone@inbox.mailinator.com',
    'our own list' => 'someone@tempmail.com',
    'marker in the name' => 'someone@new-tempmail-service.xyz',
]);

it('still lets an ordinary address sign up', function () {
    Role::findOrCreate('owner', 'web');

    $this->postJson('/api/auth/register', [
        'name' => 'Teste',
        'email' => 'someone@gmail.com',
        'password' => 'correct-horse-battery-1',
        'password_confirmation' => 'correct-horse-battery-1',
        'whatsapp_number' => '+5511999990000',
    ])->assertCreated();
});
