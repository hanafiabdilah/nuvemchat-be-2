<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\FiscalInvoiceStatus;
use App\Jobs\IssueFiscalInvoice;
use App\Models\FiscalInvoice;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\Fiscal\PlugnotasConfig;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * The net under the nota fiscal webhook.
 *
 * Plugnotas retries a webhook for about six hours and then stops; a worker can
 * die with a submission in hand; a refund can land while the prefeitura is
 * still deciding. Each pass: resend pending rows whose job was lost, read back
 * every nota still with the prefeitura (or being cancelled), and carry out
 * cancellations that were waiting for the nota to exist.
 *
 * Capped per pass and idempotent — every step converges on the same row.
 */
class SyncFiscalInvoices extends Command
{
    protected $signature = 'fiscal-invoices:sync {--limit=100 : Notas read back per pass}';

    protected $description = 'Follow platform notas fiscais (Plugnotas) whose webhook never arrived.';

    public function handle(FiscalInvoiceService $notas): int
    {
        Heartbeat::ping('fiscal-invoices:sync');

        if (PlugnotasConfig::apiKey() === null) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $stale = FiscalInvoice::query()
            ->where('status', FiscalInvoiceStatus::Pending->value)
            ->where('updated_at', '<', now()->subMinutes(FiscalInvoiceService::STALE_PENDING_MINUTES))
            ->limit($limit)
            ->pluck('id');

        foreach ($stale as $id) {
            // Touch first, so the next pass does not resend it while this job
            // is still waiting in the queue.
            FiscalInvoice::query()->whereKey($id)->update(['updated_at' => now()]);
            IssueFiscalInvoice::dispatch($id);
        }

        $following = FiscalInvoice::query()
            ->whereIn('status', [FiscalInvoiceStatus::Processing->value, FiscalInvoiceStatus::Cancelling->value])
            ->where(fn ($q) => $q->whereNull('checked_at')
                ->orWhere('checked_at', '<', now()->subMinutes(FiscalInvoiceService::RECHECK_MINUTES)))
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();

        foreach ($following as $row) {
            $notas->refresh($row);
        }

        // A refund that arrived while the nota was in flight, or whose
        // cancellation request failed: try again.
        $toCancel = FiscalInvoice::query()
            ->where('status', FiscalInvoiceStatus::Issued->value)
            ->whereNotNull('meta->cancel_requested_at')
            ->limit($limit)
            ->get();

        foreach ($toCancel as $row) {
            $notas->cancel($row);
        }

        $this->info("Resent {$stale->count()}, read back {$following->count()}, cancelled {$toCancel->count()}.");

        return self::SUCCESS;
    }
}
