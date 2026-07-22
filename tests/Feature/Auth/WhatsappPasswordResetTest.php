<?php

use App\Enums\Notification\NotificationType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Otp;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationConfig;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    // send() queues via dispatchAfterResponse, so Queue::fake() would see nothing.
    Bus::fake();
    Setting::set(NotificationConfig::KEY_ENABLED, '1');
});

function resetUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'old-password-123',
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => now(),
    ], $overrides));

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

/** Latest reset code issued to the user. */
function resetCode(User $user): string
{
    return Otp::where('user_id', $user->id)
        ->where('purpose', OtpService::PURPOSE_PASSWORD_RESET)
        ->latest('id')->firstOrFail()->code;
}

// --- Step 1: forgot -------------------------------------------------------

test('forgot issues a password_reset OTP and queues it to WhatsApp', function () {
    $user = resetUser();

    $this->postJson('/api/auth/password/forgot', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('whatsapp_number', '•••••••••8888');

    $otp = Otp::where('user_id', $user->id)->latest('id')->first();
    expect($otp->purpose)->toBe(OtpService::PURPOSE_PASSWORD_RESET);

    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class, function ($job) use ($otp) {
        return $job->type === 'otp:password_reset' && str_contains($job->message, $otp->code);
    });
});

test('forgot uses the admin-overridden template', function () {
    Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode([
        'password_reset_otp' => 'Oi {{name}}, codigo {{code}} vale {{ttl}} min.',
    ]));
    $user = resetUser();

    $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();

    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class, function ($job) use ($user) {
        return $job->message === "Oi Ana, codigo " . resetCode($user) . " vale 10 min.";
    });
});

test('forgot on an unknown email answers 200 without issuing a code', function () {
    $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('whatsapp_number', null);

    expect(Otp::count())->toBe(0);
    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('forgot stays 200 while the resend cooldown is active', function () {
    $user = resetUser();

    $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();

    // The second call is swallowed by the cooldown — one code, not two.
    expect(Otp::where('user_id', $user->id)->count())->toBe(1);
});

// --- Step 2: verify -------------------------------------------------------

test('verify accepts the code without consuming it', function () {
    $user = resetUser();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);

    $this->postJson('/api/auth/password/verify', ['email' => $user->email, 'code' => resetCode($user)])
        ->assertOk()
        ->assertJsonPath('verified', true);

    expect(Otp::where('user_id', $user->id)->first()->verified_at)->toBeNull();
});

test('verify rejects a wrong code and burns an attempt', function () {
    $user = resetUser();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);

    $this->postJson('/api/auth/password/verify', ['email' => $user->email, 'code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(Otp::where('user_id', $user->id)->first()->attempts)->toBe(1);
});

// --- Step 3: reset --------------------------------------------------------

test('reset changes the password, consumes the code and revokes tokens', function () {
    $user = resetUser();
    $user->createToken('existing');
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
    $code = resetCode($user);

    $this->postJson('/api/auth/password/reset', [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('brand-new-password', $user->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect(Otp::where('code', $code)->first()->verified_at)->not->toBeNull();

    // The reset must not double as WhatsApp verification.
    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class, fn ($job) => $job->type === 'notification:welcome_registration');
});

test('reset notifies the owner that the password changed', function () {
    $user = resetUser();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);

    $this->postJson('/api/auth/password/reset', [
        'email' => $user->email,
        'code' => resetCode($user),
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class, function ($job) {
        return $job->type === 'notification:password_changed' && str_contains($job->message, 'Ana');
    });
});

test('a spent code cannot be replayed', function () {
    $user = resetUser();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
    $code = resetCode($user);

    $payload = [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ];

    $this->postJson('/api/auth/password/reset', $payload)->assertOk();
    $this->postJson('/api/auth/password/reset', $payload)->assertStatus(422);
});

test('an expired code is refused', function () {
    $user = resetUser();
    $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
    Otp::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/auth/password/reset', [
        'email' => $user->email,
        'code' => resetCode($user),
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

test('a signup code cannot be used to reset a password', function () {
    $user = resetUser(['whatsapp_verified_at' => null]);
    app(OtpService::class)->request($user, OtpService::PURPOSE_WHATSAPP);
    $signupCode = Otp::where('purpose', OtpService::PURPOSE_WHATSAPP)->latest('id')->first()->code;

    $this->postJson('/api/auth/password/reset', [
        'email' => $user->email,
        'code' => $signupCode,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

test('reset on an unknown email reads as a bad code, not a missing account', function () {
    $this->postJson('/api/auth/password/reset', [
        'email' => 'nobody@example.com',
        'code' => '123456',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

// --- Purpose isolation ----------------------------------------------------

test('verifying a signup OTP still marks the number confirmed', function () {
    $user = resetUser(['whatsapp_verified_at' => null]);
    $service = app(OtpService::class);
    $service->request($user, OtpService::PURPOSE_WHATSAPP);
    $code = Otp::where('purpose', OtpService::PURPOSE_WHATSAPP)->latest('id')->first()->code;

    $service->verify($user, $code, OtpService::PURPOSE_WHATSAPP);

    expect($user->fresh()->whatsapp_verified_at)->not->toBeNull();
});

test('verifying a reset OTP leaves whatsapp verification untouched', function () {
    $user = resetUser(['whatsapp_verified_at' => null]);
    $service = app(OtpService::class);
    $service->request($user, OtpService::PURPOSE_PASSWORD_RESET);

    $service->verify($user, resetCode($user), OtpService::PURPOSE_PASSWORD_RESET);

    expect($user->fresh()->whatsapp_verified_at)->toBeNull();
});

test('password reset events are required and carry their own log type', function () {
    expect(NotificationType::PasswordResetOtp->isRequired())->toBeTrue();
    expect(NotificationType::PasswordResetOtp->logType())->toBe('otp:password_reset');
    expect(NotificationType::PasswordChanged->isRequired())->toBeFalse();
    expect(NotificationType::PasswordChanged->logType())->toBe('notification:password_changed');
});
