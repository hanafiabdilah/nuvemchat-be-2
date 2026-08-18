<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\AdminPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Back Office: the tables on these pages, as a file you can open in a
 * spreadsheet.
 *
 * Every report here is something people were already doing by hand — copying
 * the invoices table into a sheet for the accountant, counting customers per
 * plan for a board slide. Streamed rather than built in memory: an export is
 * exactly the request that grows past the point where an in-memory array is
 * fine, and it does so on the day the business is big enough for it to matter.
 */
class AdminReportController extends Controller
{
    public const REPORTS = [
        'customers' => 'Customers, with plan, usage and lifetime revenue',
        'subscriptions' => 'Subscriptions with status, period and price',
        'invoices' => 'Invoices with status, method and amounts',
        'connections' => 'Connections with channel, status and owner',
    ];

    public function index()
    {
        return response()->json([
            'data' => array_map(
                fn ($key, $desc) => ['key' => $key, 'description' => $desc],
                array_keys(self::REPORTS),
                self::REPORTS,
            ),
        ]);
    }

    public function download(Request $request, string $report): StreamedResponse
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404);

        $period = AdminPeriod::fromRequest($request, 365);

        AuditLog::record('report.export', "Exported the {$report} report", [
            'report' => $report,
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
        ]);

        $filename = sprintf('%s-%s.csv', $report, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($report, $period) {
            $out = fopen('php://output', 'w');

            // Excel reads a UTF-8 CSV as Latin-1 unless the file opens with a
            // BOM, which turns every Brazilian customer name into mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            match ($report) {
                'customers' => $this->customers($out),
                'subscriptions' => $this->subscriptions($out),
                'invoices' => $this->invoices($out, $period),
                'connections' => $this->connections($out),
            };

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function customers($out): void
    {
        fputcsv($out, [
            'tenant_id', 'owner', 'email', 'whatsapp', 'signed_up',
            'plan', 'subscription_status', 'users', 'connections',
            'contacts', 'conversations', 'lifetime_paid_brl',
        ]);

        Tenant::query()
            ->with(['user', 'currentSubscription.plan'])
            ->withCount(['users', 'connections', 'contacts', 'conversations'])
            ->orderBy('id')
            ->chunk(200, function ($tenants) use ($out) {
                // One aggregate for the whole chunk rather than a query per row.
                $paid = Invoice::query()
                    ->where('status', 'paid')
                    ->whereIn('tenant_id', $tenants->pluck('id'))
                    ->selectRaw('tenant_id, SUM(amount_cents) as total')
                    ->groupBy('tenant_id')
                    ->pluck('total', 'tenant_id');

                foreach ($tenants as $tenant) {
                    fputcsv($out, [
                        $tenant->id,
                        $tenant->user?->name,
                        $tenant->user?->email,
                        $tenant->user?->whatsapp_number,
                        $tenant->created_at?->toDateString(),
                        $tenant->currentSubscription?->plan?->name,
                        $tenant->currentSubscription?->status instanceof \BackedEnum
                            ? $tenant->currentSubscription->status->value
                            : $tenant->currentSubscription?->status,
                        $tenant->users_count,
                        $tenant->connections_count,
                        $tenant->contacts_count,
                        $tenant->conversations_count,
                        number_format((int) ($paid[$tenant->id] ?? 0) / 100, 2, '.', ''),
                    ]);
                }
            });
    }

    private function subscriptions($out): void
    {
        fputcsv($out, [
            'id', 'tenant_id', 'owner', 'email', 'plan', 'status',
            'price_brl', 'billing_cycle', 'period_start', 'period_end',
            'trial_ends_at', 'created_at',
        ]);

        Subscription::query()
            ->with(['tenant.user', 'plan'])
            ->orderBy('id')
            ->chunk(200, function ($subscriptions) use ($out) {
                foreach ($subscriptions as $s) {
                    fputcsv($out, [
                        $s->id,
                        $s->tenant_id,
                        $s->tenant?->user?->name,
                        $s->tenant?->user?->email,
                        $s->plan?->name,
                        $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
                        number_format((int) ($s->plan?->price_cents ?? 0) / 100, 2, '.', ''),
                        $s->plan?->billing_cycle instanceof \BackedEnum
                            ? $s->plan->billing_cycle->value
                            : $s->plan?->billing_cycle,
                        $s->current_period_start?->toDateString(),
                        $s->current_period_end?->toDateString(),
                        $s->trial_ends_at?->toDateString(),
                        $s->created_at?->toDateTimeString(),
                    ]);
                }
            });
    }

    private function invoices($out, AdminPeriod $period): void
    {
        fputcsv($out, [
            'id', 'tenant_id', 'owner', 'email', 'status', 'method',
            'amount_brl', 'currency', 'purpose', 'due_at', 'paid_at', 'created_at',
        ]);

        Invoice::query()
            ->with(['tenant.user'])
            ->whereBetween('created_at', [$period->from, $period->to])
            ->orderBy('id')
            ->chunk(500, function ($invoices) use ($out) {
                foreach ($invoices as $i) {
                    fputcsv($out, [
                        $i->id,
                        $i->tenant_id,
                        $i->tenant?->user?->name,
                        $i->tenant?->user?->email,
                        $i->status instanceof \BackedEnum ? $i->status->value : $i->status,
                        $i->payment_method instanceof \BackedEnum ? $i->payment_method->value : $i->payment_method,
                        number_format((int) $i->amount_cents / 100, 2, '.', ''),
                        $i->currency,
                        $i->purpose,
                        $i->due_at?->toDateString(),
                        $i->paid_at?->toDateTimeString(),
                        $i->created_at?->toDateTimeString(),
                    ]);
                }
            });
    }

    private function connections($out): void
    {
        fputcsv($out, [
            'id', 'tenant_id', 'owner', 'name', 'channel', 'status',
            'created_at', 'last_synced_at',
        ]);

        Connection::query()
            ->with(['tenant.user'])
            ->orderBy('id')
            ->chunk(500, function ($connections) use ($out) {
                foreach ($connections as $c) {
                    fputcsv($out, [
                        $c->id,
                        $c->tenant_id,
                        $c->tenant?->user?->name,
                        $c->name,
                        $c->channel instanceof \BackedEnum ? $c->channel->value : $c->channel,
                        $c->status instanceof \BackedEnum ? $c->status->value : $c->status,
                        $c->created_at?->toDateTimeString(),
                        $c->last_synced_at?->toDateTimeString(),
                    ]);
                }
            });
    }
}
