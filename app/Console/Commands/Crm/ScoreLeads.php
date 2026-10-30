<?php

namespace App\Console\Commands\Crm;

use App\Models\Tenant;
use App\Services\Crm\LeadScoring;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Rescore the open leads of every workspace with lead scoring on. Hourly:
 * some rules are about time ("wrote within 7 days", "open longer than 30
 * days") and stop or start being true without anybody touching the lead —
 * and tags put on a customer from the inbox land here too.
 */
class ScoreLeads extends Command
{
    protected $signature = 'crm:score-leads {--tenant= : Only this workspace}';

    protected $description = 'Recompute lead scores for workspaces with lead scoring on';

    public function handle(): int
    {
        Heartbeat::ping('crm:score-leads');

        $changed = 0;

        Tenant::query()
            ->whereNotNull('crm_lead_scoring')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'crm_lead_scoring'])
            ->filter(fn (Tenant $tenant) => LeadScoring::config($tenant)['enabled'])
            ->each(function (Tenant $tenant) use (&$changed) {
                $changed += LeadScoring::rescore($tenant->id);
            });

        $this->info("Rescored leads; {$changed} changed.");

        return self::SUCCESS;
    }
}
