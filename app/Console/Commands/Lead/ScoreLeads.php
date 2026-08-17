<?php

namespace App\Console\Commands\Lead;

use App\Enums\Lead\LeadStatus;
use App\Events\LeadUpdated;
use App\Models\Lead;
use App\Services\Lead\TemperatureScorer;
use Illuminate\Console\Command;

/**
 * Recompute every open lead's temperature.
 *
 * This is the half of the temperature axis that only a schedule can do. An
 * inbound message can push a lead warmer on the spot, but nothing *happens*
 * when a customer goes quiet — silence emits no event. Without this pass a
 * lead's score would only ever fall when something else happened to touch it,
 * which is exactly backwards: the leads worth surfacing are the ones nothing
 * has touched.
 */
class ScoreLeads extends Command
{
    protected $signature = 'leads:score
        {--tenant= : Only this tenant}
        {--chunk=200 : Rows per batch}';

    protected $description = 'Recompute lead temperature from conversation activity';

    public function handle(TemperatureScorer $scorer): int
    {
        $rescored = 0;
        $bandChanges = 0;

        Lead::query()
            ->where('status', LeadStatus::Open)
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->chunkById((int) $this->option('chunk'), function ($leads) use ($scorer, &$rescored, &$bandChanges) {
                foreach ($leads as $lead) {
                    $rescored++;

                    // Only a band change is broadcast. Scores drift for every
                    // lead every hour; putting that on the wire would make every
                    // open board flicker all day with nothing visible changing.
                    if ($scorer->apply($lead)) {
                        $bandChanges++;
                        broadcast(new LeadUpdated($lead));
                    }
                }
            });

        $this->info("Rescored {$rescored} open leads, {$bandChanges} changed band.");

        return self::SUCCESS;
    }
}
