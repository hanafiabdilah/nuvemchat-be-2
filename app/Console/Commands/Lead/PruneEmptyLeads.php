<?php

namespace App\Console\Commands\Lead;

use App\Enums\Lead\LeadSource;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Clears the cards that never had a person behind them.
 *
 * The live chat widget opens a `conversations` row when a visitor merely loads
 * the page — before they type anything, and deliberately without broadcasting
 * it, because an empty thread has nothing to show. The inbox filters those rows
 * out; the funnel did not, so every page view became a card in the first column.
 * One production workspace reached 1.652 of them against 203 real ones, and a
 * column that is 89% ghosts can no longer answer the only question it exists
 * for: who is still waiting for a first reply.
 *
 * Two shapes are removed, and both mean the same thing — a card for a
 * conversation nobody ever had:
 *
 *   1. ghost   — it holds conversations, and not one of them carries a message.
 *   2. orphan  — the thread it was opened for is gone, so it holds none at all.
 *
 * What is never removed is anything a person touched: a title, a value, an
 * owner, or a move to another column (which leaves a second stage event). The
 * same definition of "touched" the e-mail tidy-up in BackfillAttendedLeads
 * uses — whoever decided a card mattered outranks this sweep.
 *
 * Conversations are left alone on purpose. Deleting the row a live widget
 * session still points at would break that visitor's next message, and the row
 * costs nothing where it is: every read path already filters threads with no
 * messages. If one of those visitors ever writes, their opening message opens a
 * fresh card — which is the behaviour this sweep is restoring.
 *
 * Run with --dry-run first: it prints the counts per workspace and writes
 * nothing. Safe to run twice; the second run finds nothing left to do.
 */
class PruneEmptyLeads extends Command
{
    protected $signature = 'leads:prune-empty
        {--tenant= : Only this tenant}
        {--dry-run : Report what would be removed, change nothing}';

    protected $description = 'Remove untouched leads opened for conversations that never carried a message';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $totalGhosts = 0;
        $totalOrphans = 0;

        Tenant::query()
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->whereKey($tenant))
            ->chunkById(50, function ($tenants) use ($dryRun, &$rows, &$totalGhosts, &$totalOrphans) {
                foreach ($tenants as $tenant) {
                    $ghosts = $this->sweep($tenant, $this->ghosts($tenant), 'ghost', $dryRun);
                    $orphans = $this->sweep($tenant, $this->orphans($tenant), 'orphan', $dryRun);

                    if ($ghosts === 0 && $orphans === 0) {
                        continue;
                    }

                    $totalGhosts += $ghosts;
                    $totalOrphans += $orphans;

                    $rows[] = [$tenant->id, $ghosts, $orphans, $ghosts + $orphans, $this->remaining($tenant)];
                }
            });

        if ($rows === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            [
                'Tenant',
                $dryRun ? 'Ghosts to remove' : 'Ghosts removed',
                $dryRun ? 'Orphans to remove' : 'Orphans removed',
                'Total',
                'Open leads left with a real conversation',
            ],
            $rows,
        );

        $this->info(($dryRun ? 'Would remove ' : 'Removed ')
            .($totalGhosts + $totalOrphans)." leads ({$totalGhosts} ghosts, {$totalOrphans} orphans).");

        if ($dryRun) {
            $this->warn('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    /**
     * Open leads nobody has worked.
     *
     * Inbound only: a card someone typed in by hand, imported, or pushed
     * through the public API was asked for, and its lack of a conversation says
     * nothing about whether it is wanted. At most one stage event means the
     * card has never moved since it was born — its own creation writes the
     * first one.
     */
    private function untouched(Tenant $tenant): Builder
    {
        return Lead::where('tenant_id', $tenant->id)
            ->open()
            ->where('source', LeadSource::Inbound->value)
            ->whereNull('title')
            ->whereNull('value')
            ->whereNull('owner_id')
            ->has('stageEvents', '<=', 1);
    }

    /** Holds conversations, and not one of them carries a message. */
    private function ghosts(Tenant $tenant): Builder
    {
        return $this->untouched($tenant)
            ->whereHas('conversations')
            ->whereDoesntHave('conversations', fn ($conversations) => $conversations->whereHas('messages'));
    }

    /** Holds no conversation at all — the thread it was opened for is gone. */
    private function orphans(Tenant $tenant): Builder
    {
        return $this->untouched($tenant)->whereDoesntHave('conversations');
    }

    /**
     * What the first column is actually left holding, so the operator can see
     * whether the sweep did the job without opening the board.
     */
    private function remaining(Tenant $tenant): int
    {
        return Lead::where('tenant_id', $tenant->id)
            ->open()
            ->whereHas('conversations', fn ($conversations) => $conversations->whereHas('messages'))
            ->count();
    }

    private function sweep(Tenant $tenant, Builder $query, string $kind, bool $dryRun): int
    {
        $count = (clone $query)->count();

        if ($count === 0 || $dryRun) {
            return $count;
        }

        // No broadcast per card: thousands of them at once would reach every
        // open dashboard, and the board re-reads on its next load anyway.
        // Deleting a lead cascades its stage events and nulls `lead_id` on the
        // conversations it held — messages and threads are untouched.
        $query->chunkById(200, fn ($leads) => $leads->each->delete());

        Log::info('Empty leads pruned', [
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'count' => $count,
        ]);

        return $count;
    }
}
