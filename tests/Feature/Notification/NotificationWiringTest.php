<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Notification\NotificationType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Otp;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationService;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // send() queues via dispatchAfterResponse, which goes through the bus rather
    // than Queue::push — so Queue::fake() would see nothing.
    Bus::fake();
    Setting::set(NotificationConfig::KEY_ENABLED, '1');
});

function verifiedOwner(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana',
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => now(),
    ], $overrides));

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    return $user;
}

function subscriptionFor(User $owner, SubscriptionStatus $status, array $overrides = []): Subscription
{
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-' . uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
    ]);

    return Subscription::create(array_merge([
        'tenant_id' => $owner->tenant_id,
        'plan_id' => $plan->id,
        'status' => $status,
        'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9990,
        'quantity' => 1,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
    ], $overrides));
}

function sentTypes(): array
{
    $types = [];
    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class, function ($job) use (&$types) {
        $types[] = $job->type;

        return true;
    });

    return $types;
}

// --- NotificationService gates -------------------------------------------

test('a required event ignores the master switch', function () {
    Setting::set(NotificationConfig::KEY_ENABLED, '0');
    $user = verifiedOwner();

    app(NotificationService::class)->send(NotificationType::WhatsappOtp, $user->whatsapp_number, ['code' => '123456']);

    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('an optional event is blocked by the master switch', function () {
    Setting::set(NotificationConfig::KEY_ENABLED, '0');
    $user = verifiedOwner();

    app(NotificationService::class)->send(NotificationType::SubscriptionDue, $user->whatsapp_number);

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('an optional event is blocked by its own toggle', function () {
    Setting::set(NotificationConfig::KEY_EVENTS, json_encode(['subscription_due' => false]));
    $user = verifiedOwner();

    app(NotificationService::class)->send(NotificationType::SubscriptionDue, $user->whatsapp_number);

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('the OTP keeps its bare log type while others are namespaced', function () {
    expect(NotificationType::WhatsappOtp->logType())->toBe('otp');
    expect(NotificationType::SubscriptionDue->logType())->toBe('notification:subscription_due');
    expect(NotificationType::WelcomeRegistration->logType())->toBe('notification:welcome_registration');
});

test('an empty recipient is skipped rather than queued', function () {
    app(NotificationService::class)->send(NotificationType::SubscriptionDue, '   ');

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('it interpolates the template and attributes the log to the user', function () {
    $user = verifiedOwner();

    app(NotificationService::class)->send(NotificationType::WhatsappOtp, $user->whatsapp_number, [
        'code' => '424242',
        'ttl' => '10',
    ], $user->id);

    Bus::assertDispatchedAfterResponse(SendWhatsappMessageJob::class, function ($job) use ($user) {
        return $job->type === 'otp'
            && $job->to === '5511999998888'
            && $job->userId === $user->id
            && str_contains($job->message, '424242')
            && ! str_contains($job->message, '{{');
    });
});

// --- Recipient resolution -------------------------------------------------

test('an owner without a verified number is not notifiable', function () {
    $user = verifiedOwner(['whatsapp_verified_at' => null]);

    expect($user->tenant->notifiableOwner())->toBeNull();
});

test('billing skips an owner with no verified number', function () {
    $user = verifiedOwner(['whatsapp_verified_at' => null]);
    $subscription = subscriptionFor($user, SubscriptionStatus::Active);

    app(BillingService::class)->markPastDue($subscription);

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
    // The transition itself must still happen.
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

// --- Billing transitions --------------------------------------------------

test('activation notifies once when coming back from past due', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::PastDue);

    $billing = app(BillingService::class);
    $activate = new ReflectionMethod($billing, 'activate');
    $activate->invoke($billing, $subscription, now()->addMonth());

    expect(sentTypes())->toBe(['notification:subscription_activated']);
});

test('a renewal does not re-announce activation', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::Active);

    $billing = app(BillingService::class);
    $activate = new ReflectionMethod($billing, 'activate');
    $activate->invoke($billing, $subscription, now()->addMonth());

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('past due notifies once and does not extend grace on repeat calls', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::Active);
    $billing = app(BillingService::class);

    $billing->markPastDue($subscription);
    $graceAfterFirst = $subscription->fresh()->grace_ends_at;

    // Simulates reconcilePreapproval() hammering a 'paused' preapproval every 15 min:
    // the grace window used to be pushed forward each time, so suspend() never fired.
    $this->travel(2)->days();
    $billing->markPastDue($subscription->fresh());

    expect($subscription->fresh()->grace_ends_at->timestamp)->toBe($graceAfterFirst->timestamp);
    expect(sentTypes())->toBe(['notification:subscription_past_due']);
});

test('suspension notifies once', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::PastDue);
    $billing = app(BillingService::class);

    $billing->suspend($subscription);
    $billing->suspend($subscription->fresh());

    expect(sentTypes())->toBe(['notification:subscription_suspended']);
});

// --- Welcome --------------------------------------------------------------

test('the welcome rides on the first verification only', function () {
    $user = verifiedOwner(['whatsapp_verified_at' => null]);
    $otpService = app(OtpService::class);

    $make = fn () => Otp::create([
        'user_id' => $user->id,
        'whatsapp_number' => $user->whatsapp_number,
        'code' => '111111',
        'purpose' => OtpService::PURPOSE_WHATSAPP,
        'expires_at' => now()->addMinutes(10),
    ]);

    $make();
    $otpService->verify($user->fresh(), '111111');
    expect(sentTypes())->toBe(['notification:welcome_registration']);

    // A later re-verification must not welcome them again.
    Bus::fake();
    $make();
    $otpService->verify($user->fresh(), '111111');
    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

// --- Due reminders --------------------------------------------------------

test('the due reminder fires once per cycle', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::Active, [
        'current_period_end' => now()->addDay(),
    ]);

    $this->artisan('billing:send-due-reminders')->assertSuccessful();
    expect(sentTypes())->toBe(['notification:subscription_due']);
    expect($subscription->fresh()->due_reminder_sent_at)->not->toBeNull();

    Bus::fake();
    $this->artisan('billing:send-due-reminders')->assertSuccessful();
    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('a new cycle makes the subscription eligible again', function () {
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::Active, [
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
    ]);
    $subscription->forceFill(['due_reminder_sent_at' => now()->subMonth()->addDay()])->save();

    // Period rolls over: the old marker now predates current_period_start.
    $subscription->forceFill([
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addDay(),
    ])->save();

    $this->artisan('billing:send-due-reminders')->assertSuccessful();

    expect(sentTypes())->toBe(['notification:subscription_due']);
});

test('a suppressed reminder is not marked as sent', function () {
    Setting::set(NotificationConfig::KEY_ENABLED, '0');
    $user = verifiedOwner();
    $subscription = subscriptionFor($user, SubscriptionStatus::Active, [
        'current_period_end' => now()->addDay(),
    ]);

    $this->artisan('billing:send-due-reminders')->assertSuccessful();

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
    // Marking it here would claim the cycle was handled and block the reminder
    // once notifications are switched back on.
    expect($subscription->fresh()->due_reminder_sent_at)->toBeNull();
});

test('a reminder to an unreachable owner is not marked as sent', function () {
    $user = verifiedOwner(['whatsapp_verified_at' => null]);
    $subscription = subscriptionFor($user, SubscriptionStatus::Active, [
        'current_period_end' => now()->addDay(),
    ]);

    $this->artisan('billing:send-due-reminders')->assertSuccessful();

    expect($subscription->fresh()->due_reminder_sent_at)->toBeNull();
});

test('it ignores subscriptions that are not due tomorrow', function () {
    $user = verifiedOwner();
    subscriptionFor($user, SubscriptionStatus::Active, ['current_period_end' => now()->addDays(9)]);

    $this->artisan('billing:send-due-reminders')->assertSuccessful();

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});

test('it ignores comped subscriptions', function () {
    $user = verifiedOwner();
    subscriptionFor($user, SubscriptionStatus::Manual, ['current_period_end' => now()->addDay()]);

    $this->artisan('billing:send-due-reminders')->assertSuccessful();

    Bus::assertNotDispatchedAfterResponse(SendWhatsappMessageJob::class);
});
