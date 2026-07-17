<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Http\Controllers\Api\Admin\StatisticsController;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

/** The endpoint payload, without going through HTTP (this repo's feature-test harness 404s). */
function revenueData(): array
{
    return json_decode(app(StatisticsController::class)->revenue()->getContent(), true)['data'];
}

function planNamed(string $name, int $cents = 9990): Plan
{
    return Plan::create([
        'name' => $name,
        'slug' => strtolower($name) . '-' . uniqid(),
        'price_cents' => $cents,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
    ]);
}

function subscriptionOn(Plan $plan): Subscription
{
    $user = User::factory()->create(['email' => 'rev-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => $plan->price_cents,
        'quantity' => 1,
    ]);
}

function invoiceFor(Subscription $sub, InvoiceStatus $status, ?string $paidAt, int $cents = 9990, ?PaymentMethod $method = null): Invoice
{
    return Invoice::create([
        'tenant_id' => $sub->tenant_id,
        'subscription_id' => $sub->id,
        'status' => $status,
        'payment_method' => $method ?? PaymentMethod::Pix,
        'amount_cents' => $cents,
        'currency' => 'BRL',
        'paid_at' => $paidAt,
    ]);
}

function periodTotal(array $series, string $period): int
{
    foreach ($series as $point) {
        if ($point['period'] === $period) {
            return $point['total'];
        }
    }

    return -1; // absent — distinct from a real zero
}

test('it sums money received this month', function () {
    $sub = subscriptionOn(planNamed('Pro'));
    invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDay()->toDateTimeString(), 9990);
    invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDays(2)->toDateTimeString(), 5000);

    $data = revenueData();

    expect($data['totals']['this_month'])->toBe(14990);
    expect($data['totals']['all_time'])->toBe(14990);
    expect(periodTotal($data['series'], now()->format('Y-m')))->toBe(14990);
});

test('it buckets by when the money landed, not when the invoice was raised', function () {
    $sub = subscriptionOn(planNamed('Pro'));

    // Raised last month, settled this month: it belongs to this month.
    $invoice = invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDay()->toDateTimeString());
    $invoice->forceFill(['created_at' => now()->subMonth()->startOfMonth()])->save();

    $data = revenueData();

    expect(periodTotal($data['series'], now()->format('Y-m')))->toBe(9990);
    expect(periodTotal($data['series'], now()->subMonth()->format('Y-m')))->toBe(0);
    expect($data['totals']['this_month'])->toBe(9990);
    expect($data['totals']['last_month'])->toBe(0);
});

test('it ignores invoices that were never paid', function () {
    $sub = subscriptionOn(planNamed('Pro'));
    invoiceFor($sub, InvoiceStatus::Pending, null);
    invoiceFor($sub, InvoiceStatus::Failed, null);
    invoiceFor($sub, InvoiceStatus::Expired, null);
    invoiceFor($sub, InvoiceStatus::Cancelled, null);

    $data = revenueData();

    expect($data['totals']['all_time'])->toBe(0);
    expect($data['totals']['this_month'])->toBe(0);
});

test('a refunded invoice drops out of revenue on its own', function () {
    $sub = subscriptionOn(planNamed('Pro'));
    $kept = invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDay()->toDateTimeString(), 9990);
    $refunded = invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDay()->toDateTimeString(), 4000);

    expect(revenueData()['totals']['all_time'])->toBe(13990);

    // paid → refunded in place: no explicit subtraction, it simply stops matching.
    $refunded->update(['status' => InvoiceStatus::Refunded]);

    $data = revenueData();
    expect($data['totals']['all_time'])->toBe(9990);
    expect($data['refunds'])->toBe(['count' => 1, 'total' => 4000]);
    expect($kept->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('every month in the window is present, empty ones as zero', function () {
    $data = revenueData();

    expect($data['series'])->toHaveCount(12);
    expect(collect($data['series'])->pluck('total')->unique()->all())->toBe([0]);
    expect($data['series'][11]['period'])->toBe(now()->format('Y-m'));
});

test('it reports the month-over-month delta', function () {
    $sub = subscriptionOn(planNamed('Pro'));
    invoiceFor($sub, InvoiceStatus::Paid, now()->subMonth()->startOfMonth()->addDay()->toDateTimeString(), 10000);
    invoiceFor($sub, InvoiceStatus::Paid, now()->startOfMonth()->addDay()->toDateTimeString(), 15000);

    $data = revenueData();

    expect($data['totals']['last_month'])->toBe(10000);
    expect($data['totals']['this_month'])->toBe(15000);
    // Loose: JSON renders a whole float as an int, so 50.0 arrives as 50.
    expect($data['totals']['delta_pct'])->toEqual(50);
});

test('it breaks revenue down by plan', function () {
    $pro = subscriptionOn(planNamed('Pro'));
    $lite = subscriptionOn(planNamed('Lite'));
    invoiceFor($pro, InvoiceStatus::Paid, now()->toDateTimeString(), 9990);
    invoiceFor($pro, InvoiceStatus::Paid, now()->toDateTimeString(), 9990);
    invoiceFor($lite, InvoiceStatus::Paid, now()->toDateTimeString(), 3000);

    $byPlan = collect(revenueData()['by_plan'])->pluck('total', 'name');

    expect($byPlan['Pro'])->toBe(19980);
    expect($byPlan['Lite'])->toBe(3000);
});

test('it breaks revenue down by payment method', function () {
    $sub = subscriptionOn(planNamed('Pro'));
    invoiceFor($sub, InvoiceStatus::Paid, now()->toDateTimeString(), 9990, PaymentMethod::Pix);
    invoiceFor($sub, InvoiceStatus::Paid, now()->toDateTimeString(), 5000, PaymentMethod::Card);
    invoiceFor($sub, InvoiceStatus::Paid, now()->toDateTimeString(), 1000, PaymentMethod::Card);

    $byMethod = collect(revenueData()['by_method'])->pluck('total', 'method');

    expect($byMethod['pix'])->toBe(9990);
    expect($byMethod['card'])->toBe(6000);
});

test('a comped subscription contributes nothing', function () {
    $user = User::factory()->create(['email' => 'comp-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $admin = User::factory()->create(['email' => 'admin-' . uniqid() . '@example.test']);
    app(\App\Services\Billing\BillingService::class)->grantManual($tenant->fresh(), planNamed('Pro'), null, $admin);

    // grantManual raises no invoice at all, so comps cannot inflate revenue.
    expect(Invoice::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(revenueData()['totals']['all_time'])->toBe(0);
});
