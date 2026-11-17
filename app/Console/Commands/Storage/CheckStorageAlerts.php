<?php

namespace App\Console\Commands\Storage;

use App\Enums\Notification\NotificationType;
use App\Models\GalleryAsset;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\SubscriptionGate;
use App\Services\Gallery\GalleryStorage;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tells a workspace's owner, on WhatsApp, that its storage is filling up.
 *
 * Every file the workspace stores counts since Nov 2026, and a full storage
 * refuses uploads — the flow, campaign and API uploads that keep its automation
 * sending included. So the owner hears about it on the way up: once at 80%,
 * once at 95%, and once when it is full.
 *
 * "Once" is `tenants.storage_alert_level`: the level last announced. A higher
 * level is announced and stored; a lower one is only stored, so the next climb
 * is announced again. Coming down needs a margin (HYSTERESIS) — message media
 * is purged hourly, and a workspace hovering at 80% would otherwise be told
 * the same thing every hour.
 *
 * Skipped entirely where billing is not enforced (nothing is ever refused
 * there), and for workspaces whose subscription is not usable — they cannot
 * upload anything anyway, and "your storage is full" is not the thing to tell
 * someone whose account is suspended.
 */
class CheckStorageAlerts extends Command
{
    protected $signature = 'storage:check-alerts
                            {--tenant= : Only this workspace}
                            {--dry-run : Report what would be sent, send nothing}';

    protected $description = 'Warn workspace owners on WhatsApp when their storage reaches 80%, 95% and 100%';

    /** Percentage points usage must fall below a level before it can warn again. */
    private const HYSTERESIS = 5;

    public function handle(GalleryStorage $storage, SubscriptionGate $gate, BillingNotifier $notifier): int
    {
        Heartbeat::ping('storage:check-alerts');

        if (! config('services.billing.enforce')) {
            $this->info('Billing is not enforced; storage is never refused, nothing to warn about.');

            return self::SUCCESS;
        }

        $only = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $dryRun = (bool) $this->option('dry-run');

        // Workspaces that store something, plus those holding a warning that
        // may need clearing after they deleted everything.
        $ids = GalleryAsset::query()->distinct()->pluck('tenant_id')
            ->merge(Tenant::where('storage_alert_level', '>', 0)->pluck('id'))
            ->unique()
            ->when($only, fn ($c) => $c->filter(fn ($id) => (int) $id === $only));

        $sent = 0;

        foreach (Tenant::whereIn('id', $ids)->get() as $tenant) {
            $used = $storage->usedBytes($tenant);
            $limit = $storage->limitBytes($tenant);
            $level = GalleryStorage::alertLevel($used, $limit);
            $current = (int) $tenant->storage_alert_level;

            if ($level < $current) {
                // Cleared only with a margin, so hovering at a threshold does
                // not re-arm the same warning every hour.
                $cleared = $limit > 0 ? GalleryStorage::alertLevel($used + intdiv($limit * self::HYSTERESIS, 100), $limit) : $level;

                if ($cleared < $current && ! $dryRun) {
                    Tenant::whereKey($tenant->id)->update(['storage_alert_level' => $cleared]);
                }

                continue;
            }

            if ($level === $current || ! $gate->usable($tenant)) {
                continue;
            }

            $percent = $limit > 0 ? min(100, intdiv($used * 100, $limit)) : 100;
            $this->line(sprintf('  workspace %d: %d%% (%s of %s) → %s', $tenant->id, $percent, GalleryAsset::formatBytes($used), GalleryAsset::formatBytes($limit), $level === 100 ? 'full' : "{$level}%"));

            if ($dryRun) {
                continue;
            }

            // Stamped before sending: two overlapping runs must not both decide
            // they are the one to warn.
            $stamped = Tenant::whereKey($tenant->id)
                ->where('storage_alert_level', $current)
                ->update(['storage_alert_level' => $level]);

            if ($stamped === 0) {
                continue;
            }

            try {
                $level === 100
                    ? $notifier->notifyTenant(NotificationType::StorageFull, $tenant, [
                        'used' => GalleryAsset::formatBytes($used),
                        'limit' => GalleryAsset::formatBytes($limit),
                    ])
                    : $notifier->notifyTenant(NotificationType::StorageAlmostFull, $tenant, [
                        'percent' => $percent,
                        'used' => GalleryAsset::formatBytes($used),
                        'limit' => GalleryAsset::formatBytes($limit),
                    ]);

                $sent++;
            } catch (\Throwable $e) {
                Log::warning('storage:check-alerts could not warn a workspace', [
                    'tenant_id' => $tenant->id,
                    'level' => $level,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(($dryRun ? 'Would warn' : 'Warned').' '.$sent.' workspace(s).');

        return self::SUCCESS;
    }
}
