<?php

use App\Enums\Notification\NotificationType;
use App\Models\Market;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What language the platform writes to a customer in.
 *
 * Every one of these messages is transactional and several are unavoidable: the
 * signup code, the password-reset code, the notice that a number is about to be
 * cancelled. Until now all twenty were Portuguese for everybody, which meant an
 * Indonesian business received its verification code in a language it had not
 * chosen, at the one moment it could not skip.
 *
 * These tests exist because the failure is silent from the inside: the message
 * sends, the log row is written, delivery succeeds. Only the person holding the
 * phone can tell that anything is wrong.
 */
uses(RefreshDatabase::class);

// Named for this file on purpose. Pest loads every test file into one process,
// so a second helper of the same name anywhere is a fatal redeclare.
function langIndonesia(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

/** An owner of a workspace in the given market. */
function langOwner(string $marketCode): User
{
    $user = User::factory()->create();

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

it('writes to a person in the language their country speaks', function () {
    langIndonesia();

    expect(langOwner('ID')->localeCode())->toBe('id')
        ->and(langOwner('BR')->localeCode())->toBe('pt_BR');
});

it('lets a person override the language their country chose', function () {
    langIndonesia();

    $user = langOwner('ID');
    $user->forceFill(['locale' => 'pt_BR'])->save();

    // A Brazilian manager running an Indonesian workspace reads Portuguese;
    // null is "follow my country", a value is that person saying otherwise.
    expect($user->fresh()->localeCode())->toBe('pt_BR');
});

it('sends the verification code in the recipient language', function () {
    langIndonesia();

    $indonesian = NotificationConfig::template(NotificationType::WhatsappOtp, 'id');
    $brazilian = NotificationConfig::template(NotificationType::WhatsappOtp, 'pt_BR');

    expect($indonesian)->toContain('Kode verifikasi')
        ->and($brazilian)->toContain('código de verificação')
        ->and($indonesian)->not->toBe($brazilian);
});

it('never lets an untranslated key reach a customer', function () {
    // The failure this guards against is not an exception — Laravel answers a
    // missing key with the key, so the customer would receive the literal
    // string "notifications.whatsapp_otp" and delivery would report success.
    foreach (NotificationType::cases() as $type) {
        foreach ($type->defaultTemplates() as $locale => $body) {
            expect($body)->not->toStartWith('notifications.', "{$type->value} / {$locale}");
            expect(trim($body))->not->toBe('', "{$type->value} / {$locale}");
        }
    }
});

it('falls back to the platform language, not to English, for a language it does not offer', function () {
    // ⚠️ The reason this test exists. Lang::has() walks to Laravel's own
    // app.fallback_locale ('en') before answering, so guarding on it alone
    // reports an unknown locale as translatable and quietly hands that
    // country's customers English. A typo in a market row must land on the
    // platform's own language instead.
    expect(NotificationType::WhatsappOtp->defaultTemplate('xx'))
        ->toBe(NotificationType::WhatsappOtp->defaultTemplate(config('markets.default_locale')))
        ->and(NotificationType::WhatsappOtp->defaultTemplate('xx'))
        ->toContain('código de verificação');
});

it('keeps an admin override to the language it was written in', function () {
    langIndonesia();

    Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode([
        'welcome_registration' => ['id' => 'Selamat datang {{name}}, khusus Indonesia!'],
    ]));

    expect(NotificationConfig::template(NotificationType::WelcomeRegistration, 'id'))
        ->toBe('Selamat datang {{name}}, khusus Indonesia!');

    // And nowhere else: an override is a customisation of one language, not a
    // replacement of the message.
    expect(NotificationConfig::template(NotificationType::WelcomeRegistration, 'pt_BR'))
        ->toBe(NotificationType::WelcomeRegistration->defaultTemplate('pt_BR'));
});

it('reads a legacy override as the platform language only', function () {
    langIndonesia();

    // The shape stored before this setting had languages: one string, written
    // in Portuguese because that was the only language there was.
    Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode([
        'welcome_registration' => 'Oi {{name}}',
    ]));

    expect(NotificationConfig::template(NotificationType::WelcomeRegistration, 'pt_BR'))
        ->toBe('Oi {{name}}');

    // Handing that Portuguese sentence to an Indonesian reader would not be
    // honouring the admin's intent — it would be undoing a translation they
    // never knew existed.
    expect(NotificationConfig::template(NotificationType::WelcomeRegistration, 'id'))
        ->toBe(NotificationType::WelcomeRegistration->defaultTemplate('id'));
});

it('interpolates placeholders in whichever language was chosen', function () {
    langIndonesia();

    $rendered = app(NotificationService::class)->render(
        NotificationType::WhatsappOtp,
        ['code' => '482913', 'ttl' => '10'],
        'id',
    );

    expect($rendered)->toContain('482913')
        ->and($rendered)->toContain('10 menit')
        ->and($rendered)->not->toContain('{{');
});

it('drops a language nobody offers rather than storing it', function () {
    $stored = NotificationConfig::sanitizeTemplates([
        'welcome_registration' => ['id' => 'Halo', 'xx' => 'nonsense', 'pt_BR' => '   '],
        'not_a_real_event' => ['id' => 'Halo'],
    ]);

    // Blank is dropped because that is how the editor says "use the default";
    // storing it would pin the event to an empty message instead.
    expect($stored)->toBe(['welcome_registration' => ['id' => 'Halo']]);
});
