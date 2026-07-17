<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GrowthStats;

class StatisticsController extends Controller
{
    /**
     * Platform analytics: totals, 12-month cumulative growth per entity,
     * and channel/status breakdowns.
     */
    public function index()
    {
        $channels = Connection::selectRaw('channel, COUNT(*) as c')
            ->groupBy('channel')->get()
            ->map(fn ($r) => [
                'channel' => $r->channel instanceof Channel ? $r->channel->value : $r->channel,
                'count' => (int) $r->c,
            ])->values();

        $statuses = Connection::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')->get()
            ->map(fn ($r) => [
                'status' => $r->status instanceof Status ? $r->status->value : $r->status,
                'count' => (int) $r->c,
            ])->values();

        return response()->json([
            'data' => [
                'totals' => [
                    'customers' => Tenant::count(),
                    'users' => User::whereNotNull('tenant_id')->count(),
                    'connections' => Connection::count(),
                    'conversations' => Conversation::count(),
                    'contacts' => Contact::count(),
                ],
                'growth' => [
                    'customers' => GrowthStats::cumulative(fn () => Tenant::query()),
                    'users' => GrowthStats::cumulative(fn () => User::whereNotNull('tenant_id')),
                    'connections' => GrowthStats::cumulative(fn () => Connection::query()),
                    'conversations' => GrowthStats::cumulative(fn () => Conversation::query()),
                ],
                'channels' => $channels,
                'statuses' => $statuses,
            ],
        ]);
    }

    /**
     * Money actually received, bucketed by when it landed (`paid_at`).
     *
     * Net by construction: a refund flips the invoice `paid → refunded` in place,
     * so it drops out of these sums on its own — the `refunds` block below is for
     * visibility, not a second subtraction. Comped subscriptions raise no invoice
     * and so contribute nothing, which is correct.
     *
     * Caveat: with no `refunded_at` column, a refund reduces the month the invoice
     * was *paid*, so a past month's figure can change retroactively.
     */
    public function revenue()
    {
        // Qualified: by_plan joins subscriptions, which has its own `status`.
        $paid = fn () => Invoice::query()->where('invoices.status', InvoiceStatus::Paid->value);

        $months = GrowthStats::months();
        $expr = GrowthStats::monthExpr('paid_at');

        $byMonth = $paid()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $months->first())
            ->selectRaw("$expr as period, SUM(amount_cents) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        // Seed every month so gaps render as zero rather than vanishing.
        $series = $months->map(fn ($m) => [
            'period' => $m->format('Y-m'),
            'total' => (int) ($byMonth[$m->format('Y-m')] ?? 0),
        ])->values()->all();

        $sumBetween = fn ($from, $to) => (int) $paid()
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_cents');

        $thisMonth = $sumBetween(now()->startOfMonth(), now()->endOfMonth());
        $lastMonth = $sumBetween(now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());

        $byPlan = $paid()
            ->join('subscriptions', 'invoices.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->selectRaw('plans.name as name, SUM(invoices.amount_cents) as total')
            ->groupBy('plans.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['name' => $r->name ?? '—', 'total' => (int) $r->total])
            ->values();

        $byMethod = $paid()
            ->selectRaw('payment_method, SUM(amount_cents) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->payment_method instanceof PaymentMethod
                    ? $r->payment_method->value
                    : $r->payment_method,
                'total' => (int) $r->total,
            ])->values();

        $refunded = Invoice::query()->where('status', InvoiceStatus::Refunded->value);

        return response()->json([
            'data' => [
                // plans.currency and invoices.currency are char(3) default BRL and
                // nothing writes anything else today.
                'currency' => Invoice::query()->value('currency') ?? 'BRL',
                'totals' => [
                    'this_month' => $thisMonth,
                    'last_month' => $lastMonth,
                    'delta_pct' => $lastMonth === 0
                        ? ($thisMonth > 0 ? 100.0 : 0.0)
                        : round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1),
                    'all_time' => (int) $paid()->sum('amount_cents'),
                ],
                'series' => $series,
                'by_plan' => $byPlan,
                'by_method' => $byMethod,
                'refunds' => [
                    'count' => (clone $refunded)->count(),
                    'total' => (int) (clone $refunded)->sum('amount_cents'),
                ],
            ],
        ]);
    }
}
