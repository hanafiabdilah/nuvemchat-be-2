<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot decommission of PRE-PARTNER API Way instances (created directly on
 * the core via the integrator token, before purchases moved to the ProxyBR
 * partner API). Disconnects the session, deletes the instance on the core and
 * removes the connection row. Run once per environment BEFORE going live with
 * the partner flow; keep the command around as the runbook.
 *
 *   php artisan apiway:decommission-legacy          # dry-run (list only)
 *   php artisan apiway:decommission-legacy --force  # actually delete
 */
class DecommissionLegacyApiwayInstances extends Command
{
    protected $signature = 'apiway:decommission-legacy
                            {--force : Actually disconnect/delete (default is a dry-run)}';

    protected $description = 'Disconnect and delete legacy integrator-created API Way instances and their connections';

    public function handle(): int
    {
        $legacy = Connection::query()
            ->where('channel', Channel::WhatsappApiway->value)
            ->whereNotNull('credentials')
            ->get()
            // Partner-linked connections carry apiway_instance_id; legacy ones don't.
            ->filter(fn (Connection $c) => ! empty($c->credentials['instance_id'])
                && empty($c->credentials['apiway_instance_id']));

        if ($legacy->isEmpty()) {
            $this->info('No legacy API Way connections found.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $base = ApiwayConfig::baseUrl();
        $integratorToken = ApiwayConfig::integratorToken();

        if ($force && empty($integratorToken)) {
            $this->error('Integrator token not configured — cannot delete instances on the core.');

            return self::FAILURE;
        }

        foreach ($legacy as $connection) {
            $instanceId = $connection->credentials['instance_id'];
            $this->line("Connection #{$connection->id} (tenant {$connection->tenant_id}) — instance {$instanceId}");

            if (! $force) {
                continue;
            }

            // 1. Disconnect the session (instance token), best-effort.
            try {
                Http::withToken($connection->credentials['token'] ?? '')
                    ->connectTimeout(15)->timeout(20)
                    ->get("{$base}/v1/instance/disconnect?instanceId={$instanceId}");
                $this->line('  session disconnected');
            } catch (\Throwable $e) {
                $this->warn("  disconnect failed (continuing): {$e->getMessage()}");
            }

            // 2. Delete the instance on the core (integrator token), best-effort.
            try {
                Http::withToken($integratorToken)
                    ->connectTimeout(15)->timeout(20)
                    ->delete("{$base}/v1/delete-instance?instanceId={$instanceId}");
                $this->line('  instance deleted on core');
            } catch (\Throwable $e) {
                $this->warn("  delete failed (verify manually): {$e->getMessage()}");
            }

            // 3. Drop the local connection row.
            $connection->delete();
            $this->line('  connection removed');
        }

        $this->info($force
            ? "Decommissioned {$legacy->count()} legacy connection(s)."
            : "Dry-run: {$legacy->count()} legacy connection(s) would be decommissioned. Re-run with --force.");

        return self::SUCCESS;
    }
}
