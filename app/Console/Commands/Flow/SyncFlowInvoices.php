<?php

namespace App\Console\Commands\Flow;

use App\Enums\Flow\FlowInvoiceStatus;
use App\Models\FlowInvoice;
use App\Services\Flow\FlowInvoiceService;
use App\Services\Flow\InvoiceNodes;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The safety net under invoice nodes.
 *
 * Three passes, each capped:
 *
 *  1. Invoices whose flow deadline passed: asked once more, then the flow is
 *     released down its failed branch (the invoice stays open).
 *  2. Open invoices not checked recently: polled, so a missed webhook costs
 *     minutes, and an authorization that lands after the flow moved on is
 *     still learned about and noted.
 *  3. Invoices open past the follow-up window: called failed. A prefeitura
 *     that has not answered in a week is not going to, and a row that stays
 *     "processing" forever is a list that lies.
 */
class SyncFlowInvoices extends Command
{
    protected $signature = 'flow-invoices:sync {--limit=100 : Most invoices touched per pass, per kind}';

    protected $description = 'Confirm flow invoices whose webhook never arrived and release flows past their deadline';

    private const POLL_EVERY_MINUTES = 2;

    private const POLL_EVERY_MINUTES_AFTER_AN_HOUR = 15;

    public function handle(FlowInvoiceService $invoices): int
    {
        Heartbeat::ping('flow-invoices:sync');

        $limit = max(1, (int) $this->option('limit'));
        $released = 0;
        $polled = 0;
        $abandoned = 0;

        $overdue = FlowInvoice::query()
            ->where('status', FlowInvoiceStatus::Processing)
            ->where('wait_until', '<=', now()->subMinute())
            ->orderBy('wait_until')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (FlowInvoice $invoice) => ($invoice->meta['released_at'] ?? null) === null)
            ->take($limit);

        foreach ($overdue as $invoice) {
            try {
                $invoices->release($invoice);
                $released++;
            } catch (\Throwable $e) {
                Log::warning('flow-invoices:sync could not release an invoice', [
                    'flow_invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $open = FlowInvoice::query()
            ->where('status', FlowInvoiceStatus::Processing)
            ->where('created_at', '<=', now()->subMinute())
            ->where('created_at', '>', now()->subDays(InvoiceNodes::FOLLOW_UP_DAYS))
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhere(fn ($young) => $young
                        ->where('created_at', '>', now()->subHour())
                        ->where('last_checked_at', '<=', now()->subMinutes(self::POLL_EVERY_MINUTES)))
                    ->orWhere('last_checked_at', '<=', now()->subMinutes(self::POLL_EVERY_MINUTES_AFTER_AN_HOUR));
            })
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        foreach ($open as $invoice) {
            try {
                $invoices->refresh($invoice);
                $polled++;
            } catch (\Throwable $e) {
                Log::warning('flow-invoices:sync could not poll an invoice', [
                    'flow_invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stale = FlowInvoice::query()
            ->where('status', FlowInvoiceStatus::Processing)
            ->where('created_at', '<=', now()->subDays(InvoiceNodes::FOLLOW_UP_DAYS))
            ->limit($limit)
            ->get();

        foreach ($stale as $invoice) {
            $invoices->settle($invoice, new \App\Services\Integrations\Invoices\InvoiceResult(
                status: FlowInvoiceStatus::Failed,
                failureReason: 'A emissão não teve resposta da prefeitura/SEFAZ em '.InvoiceNodes::FOLLOW_UP_DAYS.' dias. Confira a nota no painel do emissor.',
                providerStatus: 'abandoned',
            ));
            $abandoned++;
        }

        $this->info("Released {$released}, polled {$polled}, abandoned {$abandoned}.");

        return self::SUCCESS;
    }
}
