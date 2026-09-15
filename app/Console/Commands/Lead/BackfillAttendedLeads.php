<?php

namespace App\Console\Commands\Lead;

use App\Enums\Connection\Channel;
use App\Enums\Lead\LeadSource;
use App\Enums\Lead\StageKind;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Tenant;
use App\Services\Lead\LeadAttendance;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * One-off tidy-up of the funnels that filled up before cards moved by
 * themselves.
 *
 *  1. Removes open e-mail leads nobody touched. E-mail no longer opens cards,
 *     and these are mostly senders like notification robots. "Touched" = a
 *     title, value or owner was set, or the card was moved: those stay, since
 *     a person decided they mattered.
 *  2. Moves every open lead still before the attended stage whose
 *     conversations a person already answered into that stage — what
 *     LeadAttendance would have done had it existed at the time.
 *
 * Run with --dry-run first: it prints the counts per workspace and writes
 * nothing. Safe to run twice; the second run finds nothing left to do.
 */
class BackfillAttendedLeads extends Command
{
    protected $signature = 'leads:backfill-attended
        {--tenant= : Only this tenant}
        {--dry-run : Report what would change, change nothing}';

    protected $description = 'Move already-answered leads to the attended stage and remove untouched e-mail leads';

    public function handle(LeadAttendance $attendance): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        Tenant::query()
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->whereKey($tenant))
            ->chunkById(50, function ($tenants) use ($attendance, $dryRun, &$rows) {
                foreach ($tenants as $tenant) {
                    [$removed, $kept] = $this->tidyEmail($tenant, $dryRun);
                    $moved = $this->moveAnswered($tenant, $attendance, $dryRun);

                    if ($removed || $kept || $moved !== null) {
                        $rows[] = [$tenant->id, $moved ?? 'regra desligada', $removed, $kept];
                    }
                }
            });

        if ($rows === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tenant', $dryRun ? 'Would move' : 'Moved', $dryRun ? 'E-mail leads to remove' : 'E-mail leads removed', 'E-mail leads kept (touched)'],
            $rows,
        );

        if ($dryRun) {
            $this->warn('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} removed, kept */
    private function tidyEmail(Tenant $tenant, bool $dryRun): array
    {
        $emailOnly = fn (): Builder => Lead::where('tenant_id', $tenant->id)
            ->open()
            ->whereHas('conversations')
            ->whereDoesntHave('conversations', fn ($conversations) => $conversations
                ->whereHas('connection', fn ($connection) => $connection->where('channel', '!=', Channel::Email->value)));

        $untouched = fn (): Builder => $emailOnly()
            ->where('source', LeadSource::Inbound->value)
            ->whereNull('title')
            ->whereNull('value')
            ->whereNull('owner_id')
            ->has('stageEvents', '<=', 1);

        $total = $emailOnly()->count();
        $removable = $untouched()->count();

        if (! $dryRun && $removable > 0) {
            // Conversations keep their messages: lead_id is nulled on delete.
            $untouched()->chunkById(200, fn ($leads) => $leads->each->delete());

            Log::info('Untouched e-mail leads removed', ['tenant_id' => $tenant->id, 'count' => $removable]);
        }

        return [$removable, $total - $removable];
    }

    /** @return int|null null when the workspace has the rule switched off */
    private function moveAnswered(Tenant $tenant, LeadAttendance $attendance, bool $dryRun): ?int
    {
        $target = $attendance->targetStage($tenant);

        if (! $target) {
            return Lead::where('tenant_id', $tenant->id)->exists() ? null : 0;
        }

        $earlier = LeadStage::where('pipeline_id', $target->pipeline_id)
            ->where('kind', StageKind::Open)
            ->where('position', '<', $target->position)
            ->pluck('id');

        $query = fn (): Builder => LeadAttendance::whereAnswered(
            Lead::where('tenant_id', $tenant->id)
                ->open()
                ->where('pipeline_id', $target->pipeline_id)
                ->whereIn('stage_id', $earlier)
                // E-mail-only cards are not part of the funnel any more.
                ->whereHas('conversations', fn ($conversations) => $conversations
                    ->whereHas('connection', fn ($connection) => $connection->where('channel', '!=', Channel::Email->value)))
        );

        $count = $query()->count();

        if ($dryRun || $count === 0) {
            return $count;
        }

        // No broadcast per card: thousands of board refreshes at once would
        // reach every open dashboard; the board re-reads on its next load.
        $query()->chunkById(200, function ($leads) use ($target) {
            foreach ($leads as $lead) {
                $lead->moveToStage($target);
            }
        });

        Log::info('Answered leads moved to the attended stage', [
            'tenant_id' => $tenant->id,
            'stage_id' => $target->id,
            'count' => $count,
        ]);

        return $count;
    }
}
