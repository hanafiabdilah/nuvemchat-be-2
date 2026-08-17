<?php

namespace App\Console\Commands\Lead;

use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Events\LeadUpdated;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Tenant;
use App\Services\Lead\LeadSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retire leads nothing has happened to in a while.
 *
 * Auto-creation means every stranger who ever asked a price becomes a card, so
 * without this the board fills with people who wrote once and vanished — and a
 * board full of noise stops being read at all, which costs more than the few
 * deals this might close early.
 *
 * Every window is the tenant's own: how many days, whether it runs at all, and
 * whether it is allowed to touch cards an agent has actually worked. Nothing is
 * destroyed — the lead moves to the funnel's lost stage with a reason, keeps its
 * whole history, and one drag puts it back.
 */
class CloseStaleLeads extends Command
{
    protected $signature = 'leads:close-stale
        {--tenant= : Only this tenant}
        {--dry-run : Report what would close, change nothing}';

    protected $description = 'Close open leads that have gone quiet, per each tenant\'s own window';

    public const LOST_REASON = 'Sem resposta';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        Tenant::query()
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->whereKey($tenant))
            ->chunkById(50, function ($tenants) use (&$closed, $dryRun) {
                foreach ($tenants as $tenant) {
                    $closed += $this->sweep($tenant, $dryRun);
                }
            });

        $this->info($dryRun
            ? "Would close {$closed} stale leads."
            : "Closed {$closed} stale leads.");

        return self::SUCCESS;
    }

    private function sweep(Tenant $tenant, bool $dryRun): int
    {
        $settings = LeadSettings::for($tenant);

        if (! $settings->autoCloseEnabled) {
            return 0;
        }

        $cutoff = now()->subDays($settings->autoCloseDays);
        $closed = 0;

        // One lost stage per pipeline: a card has to be retired inside the
        // funnel it lives in, or its history would jump across boards.
        $lostStages = LeadStage::where('kind', StageKind::Lost)
            ->whereHas('pipeline', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->get()
            ->keyBy('pipeline_id');

        if ($lostStages->isEmpty()) {
            return 0;
        }

        $this->staleQuery($tenant, $cutoff, $settings)
            ->with('stage')
            ->chunkById(200, function ($leads) use ($lostStages, $dryRun, &$closed) {
                foreach ($leads as $lead) {
                    $lostStage = $lostStages->get($lead->pipeline_id);

                    if (! $lostStage) {
                        continue;
                    }

                    $closed++;

                    if ($dryRun) {
                        $this->line("  would close #{$lead->id} ({$lead->displayTitle()})");

                        continue;
                    }

                    // Actor is null on purpose: the stage event then reads as
                    // "the system did this", which is what makes an unexpected
                    // closure explainable months later.
                    $lead->moveToStage($lostStage, null, self::LOST_REASON);

                    try {
                        broadcast(new LeadUpdated($lead, moved: true));
                    } catch (\Throwable $e) {
                        Log::warning('Stale lead closed but not broadcast', [
                            'lead_id' => $lead->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($closed > 0 && ! $dryRun) {
            Log::info('Stale leads closed', [
                'tenant_id' => $tenant->id,
                'count' => $closed,
                'window_days' => $settings->autoCloseDays,
                'included_engaged' => $settings->autoCloseEngaged,
            ]);
        }

        return $closed;
    }

    /**
     * Open leads where nothing has happened either side of the conversation.
     *
     * "Quiet" has to mean both halves: a card an agent moved yesterday is alive
     * even if the customer has not written for a month, and a customer who
     * wrote this morning is alive even if nobody has moved their card since
     * spring. Only when both are past the cutoff is the lead genuinely cold.
     * created_at covers the case where neither has ever happened.
     */
    private function staleQuery(Tenant $tenant, \Illuminate\Support\Carbon $cutoff, LeadSettings $settings)
    {
        $query = Lead::where('tenant_id', $tenant->id)
            ->where('status', LeadStatus::Open)
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<', $cutoff))
            ->where(fn ($q) => $q->whereNull('stage_changed_at')->orWhere('stage_changed_at', '<', $cutoff));

        if (! $settings->autoCloseEngaged) {
            // Never advanced past the funnel's first column — nobody has
            // decided this one is worth pursuing, so nothing is being thrown
            // away by clearing it.
            $query->whereIn('stage_id', function ($sub) use ($tenant) {
                $sub->select('id')
                    ->from('lead_stages as first_stage')
                    ->whereRaw('first_stage.position = (
                        select min(position) from lead_stages inner_stage
                        where inner_stage.pipeline_id = first_stage.pipeline_id
                          and inner_stage.kind = ?
                    )', [StageKind::Open->value])
                    ->whereIn('first_stage.pipeline_id', function ($pipelines) use ($tenant) {
                        $pipelines->select('id')->from('lead_pipelines')->where('tenant_id', $tenant->id);
                    });
            });
        }

        return $query;
    }
}
