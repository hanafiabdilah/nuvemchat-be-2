<?php

namespace App\Services\Affiliates;

use App\Enums\Affiliate\CommissionStatus;
use App\Enums\Affiliate\CommissionType;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * What affiliators earn, and what they have been paid.
 *
 *  - **Earned on** paid plan charges of a workspace they brought in: the plan's
 *    cycle charges and the extras bought on it (InvoicePurpose::Subscription
 *    and ::SubscriptionAddon) — never balance top-ups or other purchases. The
 *    base is what was actually paid, after coupons and upgrade credits.
 *  - **Once** pays on that workspace's first paid plan charge only;
 *    **recurring** on every one for `recurring_months` from the first (null =
 *    for as long as the workspace pays).
 *  - Each commission **waits** AffiliateSettings::holdDays() before it can be
 *    paid, so a refund or chargeback can still take it back. A refund before
 *    payout reverses it; after payout it becomes a negative row the next
 *    payout deducts.
 *  - **Paid by hand**: an admin pays what is available (Pix, transfer) outside
 *    the system and records it with recordPayout().
 *
 * One commission per paid charge: `affiliate_commissions.invoice_id` is unique,
 * so a second notification for the same payment adds nothing. Earning never
 * stands in the way of billing — every failure here is logged and swallowed.
 */
class AffiliateCommissions
{
    public const PURPOSES = [
        InvoicePurpose::Subscription,
        InvoicePurpose::SubscriptionAddon,
    ];

    /** Called (after commit) whenever an invoice becomes paid. */
    public function onInvoicePaid(Invoice $invoice): ?AffiliateCommission
    {
        try {
            return $this->earn($invoice);
        } catch (\Throwable $e) {
            Log::error('Affiliate commission not recorded', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Called (after commit) whenever a paid invoice is refunded or charged back. */
    public function onInvoiceRefunded(Invoice $invoice): void
    {
        try {
            $this->reverse($invoice);
        } catch (\Throwable $e) {
            Log::error('Affiliate commission not reversed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function earn(Invoice $invoice): ?AffiliateCommission
    {
        if ($invoice->status !== InvoiceStatus::Paid || ! in_array($invoice->purpose, self::PURPOSES, true)) {
            return null;
        }

        $tenant = Tenant::find($invoice->tenant_id);
        $affiliate = $tenant?->referred_by_affiliate_id ? Affiliate::find($tenant->referred_by_affiliate_id) : null;

        // An affiliator switched off stops earning; what was earned stays.
        if ($affiliate === null || ! $affiliate->is_active) {
            return null;
        }

        // Earning on your own workspace is a discount, not a referral.
        if ($affiliate->user && (int) $affiliate->user->tenant_id === (int) $tenant->id) {
            return null;
        }

        $earlier = AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('invoice_id')
            ->where('invoice_id', '!=', $invoice->id)
            ->orderBy('id');

        if ($affiliate->commission_type === CommissionType::Once && $earlier->exists()) {
            return null;
        }

        if ($affiliate->commission_type === CommissionType::Recurring && $affiliate->recurring_months) {
            $first = $earlier->first()?->created_at;

            if ($first && ($invoice->paid_at ?? now())->greaterThanOrEqualTo($first->copy()->addMonths($affiliate->recurring_months))) {
                return null;
            }
        }

        $base = (int) $invoice->amount_cents;
        $amount = Money::percentOf($base, (int) $affiliate->commission_percent, $invoice->currency);

        if ($amount <= 0) {
            return null;
        }

        try {
            return AffiliateCommission::create([
                'affiliate_id' => $affiliate->id,
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id,
                'base_cents' => $base,
                'percent' => (int) $affiliate->commission_percent,
                'amount_cents' => $amount,
                'currency' => $invoice->currency,
                'status' => CommissionStatus::Pending,
                'available_at' => ($invoice->paid_at ?? now())->copy()->addDays(AffiliateSettings::holdDays()),
            ]);
        } catch (QueryException $e) {
            // The same payment notified twice: already earned.
            if (str_contains(strtolower($e->getMessage()), 'unique') || ($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }

            throw $e;
        }
    }

    protected function reverse(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            /** @var AffiliateCommission|null $commission */
            $commission = AffiliateCommission::query()->where('invoice_id', $invoice->id)->lockForUpdate()->first();

            if ($commission === null || $commission->status === CommissionStatus::Reversed) {
                return;
            }

            if ($commission->status === CommissionStatus::Pending) {
                $commission->update(['status' => CommissionStatus::Reversed, 'reversed_at' => now()]);

                return;
            }

            // Already paid out: the affiliator owes it back, deducted from the
            // next payout. Its own row, so what was paid stays as it was.
            AffiliateCommission::query()->firstOrCreate(
                ['reversal_of_id' => $commission->id],
                [
                    'affiliate_id' => $commission->affiliate_id,
                    'tenant_id' => $commission->tenant_id,
                    'base_cents' => -$commission->base_cents,
                    'percent' => $commission->percent,
                    'amount_cents' => -$commission->amount_cents,
                    'currency' => $commission->currency,
                    'status' => CommissionStatus::Pending,
                    'available_at' => now(),
                ],
            );
        });
    }

    /**
     * What an affiliator has, per currency: waiting out the holding period,
     * available to pay, and paid. Amounts in two currencies are not one number.
     *
     * @return list<array{currency: string, holding_cents: int, available_cents: int, paid_cents: int}>
     */
    public function balances(Affiliate $affiliate): array
    {
        $rows = AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', '!=', CommissionStatus::Reversed->value)
            ->get(['currency', 'status', 'available_at', 'amount_cents']);

        $out = [];

        foreach ($rows as $row) {
            $bucket = &$out[$row->currency];
            $bucket ??= ['currency' => $row->currency, 'holding_cents' => 0, 'available_cents' => 0, 'paid_cents' => 0];

            if ($row->status === CommissionStatus::Paid) {
                $bucket['paid_cents'] += $row->amount_cents;
            } elseif ($row->available_at && $row->available_at->isPast()) {
                $bucket['available_cents'] += $row->amount_cents;
            } else {
                $bucket['holding_cents'] += $row->amount_cents;
            }

            unset($bucket);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * How the link is doing: visitors, workspaces signed up, and how many of
     * them have paid at least once.
     *
     * @return array{clicks: int, signups: int, customers: int}
     */
    public function stats(Affiliate $affiliate): array
    {
        return [
            'clicks' => $affiliate->clicks()->count(),
            'signups' => $affiliate->referrals()->count(),
            'customers' => AffiliateCommission::query()
                ->where('affiliate_id', $affiliate->id)
                ->whereNotNull('invoice_id')
                ->distinct()
                ->count('tenant_id'),
        ];
    }

    /**
     * What the affiliate pages chart (the Back Office's and the affiliator's
     * own): visitors and sign-ups per day for the last 30 days, and
     * commissions earned and paid per month for the last 12, per currency
     * (two currencies are not one bar).
     *
     * @return array<string, mixed>
     */
    public function activity(Affiliate $affiliate): array
    {
        $tz = config('app.timezone');
        $from = now($tz)->startOfDay()->subDays(29);

        $clicks = AffiliateClick::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('day', '>=', $from->toDateString())
            ->selectRaw('day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total]);

        $signups = Tenant::query()
            ->where('referred_by_affiliate_id', $affiliate->id)
            ->where('referred_at', '>=', $from->copy()->utc())
            ->pluck('referred_at')
            ->countBy(fn ($at) => $at->copy()->setTimezone($tz)->toDateString());

        $days = [];

        for ($day = $from->copy(); $day->lte(now($tz)); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'clicks' => $clicks[$key] ?? 0, 'signups' => $signups[$key] ?? 0];
        }

        $since = now($tz)->startOfMonth()->subMonths(11);
        $months = [];

        $rows = AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', '!=', CommissionStatus::Reversed->value)
            ->where(fn ($q) => $q->where('created_at', '>=', $since->copy()->utc())->orWhere('paid_at', '>=', $since->copy()->utc()))
            ->get(['currency', 'amount_cents', 'created_at', 'paid_at']);

        foreach ($rows as $row) {
            $earnedMonth = $row->created_at?->copy()->setTimezone($tz)->format('Y-m');
            $paidMonth = $row->paid_at?->copy()->setTimezone($tz)->format('Y-m');

            if ($earnedMonth && $row->created_at->gte($since)) {
                $months[$row->currency][$earnedMonth]['earned_cents'] = ($months[$row->currency][$earnedMonth]['earned_cents'] ?? 0) + $row->amount_cents;
            }

            if ($paidMonth && $row->paid_at->gte($since)) {
                $months[$row->currency][$paidMonth]['paid_cents'] = ($months[$row->currency][$paidMonth]['paid_cents'] ?? 0) + $row->amount_cents;
            }
        }

        $series = [];

        foreach ($months as $currency => $byMonth) {
            $points = [];

            for ($month = $since->copy(); $month->lte(now($tz)); $month->addMonth()) {
                $key = $month->format('Y-m');
                $points[] = [
                    'month' => $key,
                    'earned_cents' => $byMonth[$key]['earned_cents'] ?? 0,
                    'paid_cents' => $byMonth[$key]['paid_cents'] ?? 0,
                ];
            }

            $series[] = ['currency' => $currency, 'months' => $points];
        }

        return ['days' => $days, 'commissions' => $series];
    }

    /**
     * Record that an admin paid a chosen set of commissions. They must all
     * belong to one affiliator, be in one currency and be past their holding
     * period — one payout is one transfer, to one person, in one currency.
     * A clawback (a negative row) can be chosen alongside commissions: it is
     * deducted from them, and the total must still be above zero.
     *
     * @param  list<int>  $ids
     */
    public function payCommissions(array $ids, string $method, ?string $reference, ?string $note, ?Admin $admin): AffiliatePayout
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $first = AffiliateCommission::query()->whereIn('id', $ids)->orderBy('id')->first();

        if ($first === null) {
            throw ValidationException::withMessages(['commission_ids' => __('Choose at least one commission.')]);
        }

        return Cache::lock("affiliate:payout:{$first->affiliate_id}", 30)->block(10, function () use ($ids, $first, $method, $reference, $note, $admin) {
            return DB::transaction(function () use ($ids, $first, $method, $reference, $note, $admin) {
                $commissions = AffiliateCommission::query()->whereIn('id', $ids)->lockForUpdate()->get();

                if ($commissions->count() !== count($ids)) {
                    throw ValidationException::withMessages(['commission_ids' => __('Some of these commissions no longer exist.')]);
                }

                if ($commissions->pluck('affiliate_id')->unique()->count() > 1) {
                    throw ValidationException::withMessages(['commission_ids' => __('A payment can only pay one affiliate. Choose commissions of the same affiliate.')]);
                }

                if ($commissions->pluck('currency')->unique()->count() > 1) {
                    throw ValidationException::withMessages(['commission_ids' => __('A payment is in one currency. Choose commissions in the same currency.')]);
                }

                // Read again under the lock: another admin may have paid one,
                // or a refund reversed it, since the page was loaded.
                $notPayable = $commissions->first(fn (AffiliateCommission $row) => $row->state() !== 'available');

                if ($notPayable !== null) {
                    throw ValidationException::withMessages(['commission_ids' => match ($notPayable->state()) {
                        'holding' => __('A commission can only be paid after its waiting period.'),
                        'paid' => __('This commission has already been paid.'),
                        default => __('This commission was reversed and cannot be paid.'),
                    }]);
                }

                $total = (int) $commissions->sum('amount_cents');

                if ($total <= 0) {
                    throw ValidationException::withMessages(['commission_ids' => __('The refunds deducted are larger than the commissions chosen. Choose more commissions to pay.')]);
                }

                $currency = $first->currency;

                $payout = AffiliatePayout::create([
                    'affiliate_id' => $first->affiliate_id,
                    'amount_cents' => $total,
                    'currency' => $currency,
                    'method' => $method,
                    'reference' => $reference,
                    'note' => $note,
                    'paid_at' => now(),
                    'admin_id' => $admin?->id,
                ]);

                AffiliateCommission::query()
                    ->whereIn('id', $commissions->pluck('id'))
                    ->update([
                        'status' => CommissionStatus::Paid->value,
                        'payout_id' => $payout->id,
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $payout;
            });
        });
    }

    /**
     * Record that an admin paid an affiliator what is available in one
     * currency — every commission past its holding period, minus any
     * clawbacks. The money moves outside the system; this is the record of it.
     */
    public function recordPayout(Affiliate $affiliate, string $currency, string $method, ?string $reference, ?string $note, ?Admin $admin): AffiliatePayout
    {
        $currency = strtoupper($currency);

        return Cache::lock("affiliate:payout:{$affiliate->id}", 30)->block(10, function () use ($affiliate, $currency, $method, $reference, $note, $admin) {
            return DB::transaction(function () use ($affiliate, $currency, $method, $reference, $note, $admin) {
                $commissions = AffiliateCommission::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('currency', $currency)
                    ->available()
                    ->lockForUpdate()
                    ->get();

                $total = (int) $commissions->sum('amount_cents');

                if ($total <= 0) {
                    throw ValidationException::withMessages([
                        'currency' => __('There is nothing available to pay in this currency.'),
                    ]);
                }

                $payout = AffiliatePayout::create([
                    'affiliate_id' => $affiliate->id,
                    'amount_cents' => $total,
                    'currency' => $currency,
                    'method' => $method,
                    'reference' => $reference,
                    'note' => $note,
                    'paid_at' => now(),
                    'admin_id' => $admin?->id,
                ]);

                AffiliateCommission::query()
                    ->whereIn('id', $commissions->pluck('id'))
                    ->update([
                        'status' => CommissionStatus::Paid->value,
                        'payout_id' => $payout->id,
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $payout;
            });
        });
    }
}
