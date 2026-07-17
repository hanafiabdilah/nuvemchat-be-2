<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
});

function paidInvoice(InvoiceStatus $status = InvoiceStatus::Paid): Invoice
{
    $user = User::factory()->create(['email' => 'wh-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-' . uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);

    $sub = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);

    return Invoice::create([
        'tenant_id' => $tenant->id, 'subscription_id' => $sub->id,
        'status' => $status, 'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 9990, 'currency' => 'BRL',
        'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
        'mp_payment_id' => 'MP-' . uniqid(),
    ]);
}

test('a refund on a paid invoice is recorded', function () {
    // The regression: the idempotency guard returned for ANY status once paid, so the
    // 'refunded' arm was unreachable — and a refund only ever arrives for a paid
    // invoice, which made InvoiceStatus::Refunded impossible to reach at all.
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'refunded',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a chargeback on a paid invoice is recorded', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'charged_back',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a repeated approval on a paid invoice stays idempotent', function () {
    $invoice = paidInvoice();
    $paidAt = $invoice->paid_at;

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'approved',
    ]);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Paid);
    expect($fresh->paid_at->timestamp)->toBe($paidAt->timestamp); // not re-stamped
});

test('a late rejection cannot un-pay a settled invoice', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'rejected',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a late cancellation cannot un-pay a settled invoice', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'cancelled',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a rejection on a pending invoice still marks it failed', function () {
    $invoice = paidInvoice(InvoiceStatus::Pending);

    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->mp_payment_id,
        'status' => 'rejected',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Failed);
});
