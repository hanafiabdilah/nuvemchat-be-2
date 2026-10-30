<?php

namespace App\Console\Commands\Crm;

use App\Services\Crm\Automation\CrmAutomations;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Start the CRM automations that are about time passing — a deal sitting in
 * its stage, a lead nobody has touched, a task past its due time. No save
 * announces those, so something has to look.
 */
class ScanAutomations extends Command
{
    protected $signature = 'crm:automations-scan {--tenant= : Only this workspace}';

    protected $description = 'Queue CRM automations whose time-based trigger has come';

    public function handle(): int
    {
        Heartbeat::ping('crm:automations-scan');

        $queued = CrmAutomations::scan($this->option('tenant') ? (int) $this->option('tenant') : null);

        $this->info("Queued {$queued} automation run(s).");

        return self::SUCCESS;
    }
}
