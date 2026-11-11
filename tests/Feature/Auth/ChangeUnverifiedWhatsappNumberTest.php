<?php

use App\Models\Otp;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationConfig;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

/**
 * A customer who mistyped their WhatsApp number at signup is stuck on the
 * verification screen: the code goes to a phone they do not have, and the
 * Settings flow that changes a number sits behind the very gate they cannot
 * pass. POST /auth/otp/number replaces the unverified number and sends the
 * code to the right one.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Setting::set(NotificationConfig::KEY_ENABLED, '1');
});

function numberFixUser(array $overrides = []): User
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

function newestSignupCode(User $user): Otp
{
    return Otp::where('user_id', $user->id)
        ->where('purpose', OtpService::PURPOSE_WHATSAPP)
        ->latest('id')
        ->firstOrFail();
}

it('replaces a mistyped number and sends the code to the new one at once', function () {
    $user = numberFixUser();
    // The signup code, sent seconds ago to the wrong phone: its resend
    // cooldown must not hold back the code for the right one.
    $wrongPhoneCode = app(OtpService::class)->request($user);

    $this->actingAs($user)
        ->postJson('/api/auth/otp/number', [
            'whatsapp_number' => '+55 21 98765-4321',
            'current_password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('whatsapp_number_full', '5521987654321')
        ->assertJsonPath('ttl_minutes', OtpService::ttlMinutes());

    expect($user->fresh()->whatsapp_number)->toBe('5521987654321')
        ->and($user->fresh()->whatsapp_verified_at)->toBeNull();

    $fresh = newestSignupCode($user);
    expect($fresh->id)->not->toBe($wrongPhoneCode->id)
        ->and($fresh->whatsapp_number)->toBe('5521987654321')
        // The code still alive on the wrong phone is expired, so it can never
        // stamp that phone back onto the account.
        ->and($wrongPhoneCode->fresh()->isExpired())->toBeTrue();

    $this->postJson('/api/auth/otp/verify', ['code' => $fresh->code])
        ->assertOk()
        ->assertJsonPath('verified', true);

    $user->refresh();
    expect($user->whatsapp_number)->toBe('5521987654321')
        ->and($user->whatsapp_verified_at)->not->toBeNull();
});

it('lets the old code no longer verify once the number is replaced', function () {
    $user = numberFixUser();
    $oldCode = app(OtpService::class)->request($user)->code;

    $this->actingAs($user)->postJson('/api/auth/otp/number', [
        'whatsapp_number' => '5521987654321',
        'current_password' => 'correct-horse-battery',
    ])->assertOk();

    // Verify checks the newest code — the one sent to the new number.
    $newCode = newestSignupCode($user)->code;
    if ($newCode !== $oldCode) {
        $this->postJson('/api/auth/otp/verify', ['code' => $oldCode])->assertStatus(422);
    }

    expect($user->fresh()->whatsapp_verified_at)->toBeNull();
});

it('asks for the current password, and changes nothing without it', function () {
    $user = numberFixUser();

    $this->actingAs($user)
        ->postJson('/api/auth/otp/number', [
            'whatsapp_number' => '5521987654321',
            'current_password' => 'wrong',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect($user->fresh()->whatsapp_number)->toBe('5511999998888')
        ->and(Otp::where('user_id', $user->id)->count())->toBe(0);
});

it('refuses a number that is already verified, pointing to Settings', function () {
    $user = numberFixUser(['whatsapp_verified_at' => now()]);

    $this->actingAs($user)
        ->postJson('/api/auth/otp/number', [
            'whatsapp_number' => '5521987654321',
            'current_password' => 'correct-horse-battery',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'whatsapp_already_verified');

    expect($user->fresh()->whatsapp_number)->toBe('5511999998888');
});

it('refuses the same number and a number without a country code', function () {
    $user = numberFixUser();
    $this->actingAs($user);

    $this->postJson('/api/auth/otp/number', [
        'whatsapp_number' => '+55 11 99999-8888',
        'current_password' => 'correct-horse-battery',
    ])->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');

    $this->postJson('/api/auth/otp/number', [
        'whatsapp_number' => '12345',
        'current_password' => 'correct-horse-battery',
    ])->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');

    expect($user->fresh()->whatsapp_number)->toBe('5511999998888');
});

it('does not reset a verification block', function () {
    $user = numberFixUser();
    $this->actingAs($user);

    // Spend two codes: the second one blocks verification for an hour.
    foreach (range(1, 2) as $round) {
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/otp/send')->assertOk();
        $right = newestSignupCode($user)->code;
        $wrong = $right === '000000' ? '111111' : '000000';
        foreach (range(1, 3) as $try) {
            $this->postJson('/api/auth/otp/verify', ['code' => $wrong]);
        }
    }

    $this->postJson('/api/auth/otp/number', [
        'whatsapp_number' => '5521987654321',
        'current_password' => 'correct-horse-battery',
    ])->assertStatus(429)->assertJsonPath('code', 'otp_blocked');

    expect($user->fresh()->whatsapp_number)->toBe('5511999998888');
});

it('shows the full number on the verification screen, so a typo can be seen', function () {
    $user = numberFixUser();

    $this->actingAs($user)
        ->getJson('/api/auth/otp/status')
        ->assertOk()
        ->assertJsonPath('whatsapp_number_full', '5511999998888')
        ->assertJsonPath('whatsapp_number', '•••••••••8888');
});

it('bounds how often one destination can be sent a code this way', function () {
    foreach (range(1, 5) as $i) {
        $this->actingAs(numberFixUser())->postJson('/api/auth/otp/number', [
            'whatsapp_number' => '5521987654321',
            'current_password' => 'correct-horse-battery',
        ])->assertOk();
    }

    $this->actingAs(numberFixUser())->postJson('/api/auth/otp/number', [
        'whatsapp_number' => '5521987654321',
        'current_password' => 'correct-horse-battery',
    ])->assertStatus(429);
});
