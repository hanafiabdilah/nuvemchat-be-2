<?php

namespace App\Jobs\Crm;

use App\Services\Crm\LeadScoring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Rescore a workspace's open leads after its rules changed — for big workspaces. */
class RescoreLeads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $tenantId) {}

    public function handle(): void
    {
        LeadScoring::rescore($this->tenantId);
    }
}
