<?php

use App\Enums\Credit\CreditTransactionType;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Tenant;
use App\Models\User;
use App\Enums\Notification\NotificationType;
use App\Services\Billing\BillingNotifier;
use App\Services\Credits\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The wallet's general-purpose half: charging something whose price was known
 * in advance, and giving it back when it could not be delivered.
 *
 * Separate from CreditTest, which is about AI runs — the two halves have
 * opposite rules on overdraft, and a file that mixed them would keep having to
 * say which kind of debit it meant.
 */
function ledgerTenant(int $balanceCents = 0): Tenant
{
    $user = User::factory()->create(['email' => 'ledger-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    if ($balanceCents !== 0) {
        CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balanceCents, 'currency' => 'BRL']);
    }

    return $tenant;
}

it('charges a known price and moves the balance', function () {
    $tenant = ledgerTenant(10_000);

    $transaction = app(CreditService::class)->debit(
        $tenant,
        3_500,
        CreditTransactionType::Purchase,
        'apiway:buy:1',
        'API Way — 1 instância',
    );

    expect($transaction)->not->toBeNull()
        ->and($transaction->amount_cents)->toBe(-3_500)
        ->and($transaction->balance_after_cents)->toBe(6_500)
        ->and($transaction->reference)->toBe('apiway:buy:1');

    expect(app(CreditService::class)->balanceCents($tenant))->toBe(6_500);
});

it('charges a reference only once, however often the caller retries', function () {
    $tenant = ledgerTenant(10_000);
    $credits = app(CreditService::class);

    $credits->debit($tenant, 3_000, CreditTransactionType::Renewal, 'apiway:renew:7:2026-10-01', 'Renovação');
    // A queue worker that died after the write and ran the whole handler again.
    $second = $credits->debit($tenant, 3_000, CreditTransactionType::Renewal, 'apiway:renew:7:2026-10-01', 'Renovação');

    expect($second)->toBeNull()
        ->and($credits->balanceCents($tenant))->toBe(7_000)
        ->and(CreditTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('lets the same subscription be renewed again in the next cycle', function () {
    // The reason the reference carries the period: a unique key on the
    // subscription alone would allow one renewal ever.
    $tenant = ledgerTenant(10_000);
    $credits = app(CreditService::class);

    $credits->debit($tenant, 3_000, CreditTransactionType::Renewal, 'apiway:renew:7:2026-10-01', 'Renovação');
    $credits->debit($tenant, 3_000, CreditTransactionType::Renewal, 'apiway:renew:7:2026-11-01', 'Renovação');

    expect($credits->balanceCents($tenant))->toBe(4_000);
});

it('refuses a purchase the balance will not cover, and takes nothing', function () {
    $tenant = ledgerTenant(2_000);
    $credits = app(CreditService::class);

    expect($credits->canAfford($tenant, 3_500))->toBeFalse();

    $attempt = fn () => $credits->debit(
        $tenant,
        3_500,
        CreditTransactionType::Purchase,
        'apiway:buy:2',
        'API Way — 1 instância',
    );

    expect($attempt)->toThrow(InsufficientCreditException::class);
    // Unlike an AI run, a purchase must never overdraw: its price was known
    // before the customer was asked to commit to it.
    expect($credits->balanceCents($tenant))->toBe(2_000)
        ->and(CreditTransaction::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('reports the shortfall so the customer can be told what to top up', function () {
    $tenant = ledgerTenant(2_000);

    try {
        app(CreditService::class)->debit(
            $tenant,
            3_500,
            CreditTransactionType::Purchase,
            'apiway:buy:3',
            'API Way',
        );
        $this->fail('The debit should have been refused.');
    } catch (InsufficientCreditException $e) {
        expect($e->shortfallCents())->toBe(1_500)
            ->and($e->balanceCents)->toBe(2_000)
            ->and($e->requiredCents)->toBe(3_500);
    }
});

it('allows a purchase that spends the balance down to exactly zero', function () {
    $tenant = ledgerTenant(3_500);

    app(CreditService::class)->debit($tenant, 3_500, CreditTransactionType::Purchase, 'apiway:buy:4', 'API Way');

    expect(app(CreditService::class)->balanceCents($tenant))->toBe(0);
});

it('gives the money back as its own row when a purchase could not be delivered', function () {
    $tenant = ledgerTenant(10_000);
    $credits = app(CreditService::class);

    $credits->debit($tenant, 3_500, CreditTransactionType::Purchase, 'apiway:buy:5', 'API Way');
    $reversal = $credits->reverseByReference($tenant, 'apiway:buy:5', 'Devolução — instância não entregue');

    expect($reversal->type)->toBe(CreditTransactionType::Reversal)
        ->and($reversal->amount_cents)->toBe(3_500)
        ->and($credits->balanceCents($tenant))->toBe(10_000);

    // The debit stays. The customer really was charged and really was paid
    // back, and a ledger that erases the first half cannot be reconciled.
    expect(CreditTransaction::where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('never pays the same failed purchase back twice', function () {
    $tenant = ledgerTenant(10_000);
    $credits = app(CreditService::class);

    $credits->debit($tenant, 3_500, CreditTransactionType::Purchase, 'apiway:buy:6', 'API Way');
    $credits->reverseByReference($tenant, 'apiway:buy:6', 'Devolução');
    // The failure handler is exactly where a job is most likely to run again.
    $second = $credits->reverseByReference($tenant, 'apiway:buy:6', 'Devolução');

    expect($second)->toBeNull()
        ->and($credits->balanceCents($tenant))->toBe(10_000);
});

it('reverses the amount actually charged, not a price re-quoted since', function () {
    $tenant = ledgerTenant(10_000);
    $credits = app(CreditService::class);

    $credits->debit($tenant, 3_500, CreditTransactionType::Purchase, 'apiway:buy:7', 'API Way');
    $reversal = $credits->reverseByReference($tenant, 'apiway:buy:7', 'Devolução');

    expect($reversal->amount_cents)->toBe(3_500);
});

it('does nothing when asked to reverse a purchase that was never charged', function () {
    // A purchase that failed before it ever reached the wallet — normal, and
    // not something the failure handler should have to know about.
    $tenant = ledgerTenant(10_000);

    expect(app(CreditService::class)->reverseByReference($tenant, 'apiway:buy:none', 'Devolução'))->toBeNull()
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(10_000);
});

it('refuses to record a debit of zero or less', function () {
    $tenant = ledgerTenant(10_000);

    expect(fn () => app(CreditService::class)->debit($tenant, 0, CreditTransactionType::Purchase, 'x', 'x'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CreditService::class)->debit($tenant, -100, CreditTransactionType::Purchase, 'y', 'y'))
        ->toThrow(InvalidArgumentException::class);
});

/*
 * The low-balance warning.
 *
 * `low_balance_notified_at` shipped with the wallet and was only ever cleared,
 * never set — designed and not built. It matters more now than it did then:
 * the balance pays the API Way renewal that keeps a WhatsApp number alive, and
 * ProxyBR revokes on expiry with no grace period.
 */

it('warns the workspace when a debit takes the balance under the threshold', function () {
    config()->set('ai.credits.low_balance_cents', 500);
    $tenant = ledgerTenant(1_000);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldReceive('notifyTenant')
        ->once()
        ->with(NotificationType::CreditLowBalance, Mockery::any(), Mockery::any());
    app()->instance(BillingNotifier::class, $notifier);

    app(CreditService::class)->debit($tenant, 700, CreditTransactionType::Purchase, 'x:1', 'x');

    expect(CreditWallet::where('tenant_id', $tenant->id)->value('low_balance_notified_at'))->not->toBeNull();
});

it('warns only once per drop, however many debits follow', function () {
    config()->set('ai.credits.low_balance_cents', 500);
    $tenant = ledgerTenant(1_000);

    $notifier = Mockery::mock(BillingNotifier::class);
    // A workspace sitting just under the line pays for runs all afternoon; one
    // message per run would be the reason nobody reads the next one.
    $notifier->shouldReceive('notifyTenant')->once();
    app()->instance(BillingNotifier::class, $notifier);

    $credits = app(CreditService::class);
    $credits->debit($tenant, 700, CreditTransactionType::Purchase, 'x:1', 'x');
    $credits->debit($tenant, 100, CreditTransactionType::Purchase, 'x:2', 'x');
    $credits->debit($tenant, 100, CreditTransactionType::Purchase, 'x:3', 'x');
});

it('warns again after a top-up has brought the balance back up', function () {
    config()->set('ai.credits.low_balance_cents', 500);
    $tenant = ledgerTenant(1_000);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldReceive('notifyTenant')->twice();
    app()->instance(BillingNotifier::class, $notifier);

    $credits = app(CreditService::class);
    $credits->debit($tenant, 700, CreditTransactionType::Purchase, 'x:1', 'x');

    // The top-up clears the stamp, which is what makes the next drop notifiable.
    $credits->adjust($tenant, 5_000, 'recarga');
    CreditWallet::where('tenant_id', $tenant->id)->update(['low_balance_notified_at' => null]);

    $credits->debit($tenant, 5_000, CreditTransactionType::Purchase, 'x:2', 'x');
});

it('never warns a workspace whose balance only went up', function () {
    config()->set('ai.credits.low_balance_cents', 500);
    $tenant = ledgerTenant(0);

    $notifier = Mockery::mock(BillingNotifier::class);
    // A workspace that has never spent is not "running out" — it has not begun.
    $notifier->shouldNotReceive('notifyTenant');
    app()->instance(BillingNotifier::class, $notifier);

    app(CreditService::class)->adjust($tenant, 100, 'cortesia');

    expect(CreditWallet::where('tenant_id', $tenant->id)->value('low_balance_notified_at'))->toBeNull();
});

it('records the debit even when the warning blows up', function () {
    config()->set('ai.credits.low_balance_cents', 500);
    $tenant = ledgerTenant(1_000);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldReceive('notifyTenant')->andThrow(new RuntimeException('whatsapp down'));
    app()->instance(BillingNotifier::class, $notifier);

    // This runs in the same call that just charged for a reply on its way to a
    // customer. A messaging outage must not become a failed reply.
    app(CreditService::class)->debit($tenant, 700, CreditTransactionType::Purchase, 'x:1', 'x');

    expect(app(CreditService::class)->balanceCents($tenant))->toBe(300);
});
