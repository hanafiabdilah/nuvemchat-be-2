<?php

namespace App\Jobs\Crm;

use App\Enums\Crm\AutomationRunStatus;
use App\Models\CrmAutomation;
use App\Models\CrmAutomationRun;
use App\Services\Crm\Automation\AutomationActions;
use App\Services\Crm\Automation\AutomationConditions;
use App\Services\Crm\Automation\AutomationSubjects;
use App\Services\Crm\Automation\CrmAutomations;
use App\Services\Crm\CrmTimeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One run of a CRM automation, recorded on its history row.
 *
 * The record is read again here, not carried from the trigger: between the
 * two it may have been deleted, changed, or handed to someone, and an
 * automation acting on a picture of the past is how leads get reassigned
 * away from the person who just took them.
 *
 * Tried once. A second try is not a retry of one action: it runs every action
 * again, and "create a follow-up task" twice is two tasks.
 */
class RunCrmAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * After this many runs in a row that failed outright, the automation is
     * paused: something it names is gone, and running it on every record only
     * fills the history with the same failure.
     */
    public const PAUSE_AFTER_FAILURES = 10;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function handle(AutomationActions $actions): void
    {
        $run = CrmAutomationRun::find($this->runId);

        if (! $run || $run->status !== AutomationRunStatus::Queued) {
            return;
        }

        $automation = CrmAutomation::find($run->automation_id);

        if (! $automation || ! $automation->is_active) {
            $this->finish($run, AutomationRunStatus::Skipped, [], 'automation_off');

            return;
        }

        $subject = AutomationSubjects::find($run->subject_type, (int) $run->subject_id);

        if (! $subject) {
            $this->finish($run, AutomationRunStatus::Skipped, [], 'subject_gone');

            return;
        }

        if ($automation->trigger->scanned() && ! CrmAutomations::due($automation)->whereKey($subject->getKey())->exists()) {
            $this->finish($run, AutomationRunStatus::Skipped, [], 'no_longer_due');

            return;
        }

        if (! AutomationConditions::matches($subject, $automation->match, $automation->conditions ?? [])) {
            $this->finish($run, AutomationRunStatus::Skipped, [], 'conditions_not_met');

            return;
        }

        $results = CrmAutomations::muted(fn () => CrmTimeline::attributing(
            ['automation' => $automation->name, 'automation_id' => $automation->id],
            fn () => $actions->run($automation, $subject),
        ));

        $statuses = array_column($results, 'status');
        $failed = in_array(AutomationActions::FAILED, $statuses, true);
        $done = in_array(AutomationActions::DONE, $statuses, true);

        $status = match (true) {
            $failed && $done => AutomationRunStatus::Partial,
            $failed => AutomationRunStatus::Failed,
            default => AutomationRunStatus::Succeeded,
        };

        $this->finish($run, $status, $results);
        $this->tally($automation, $status);
    }

    public function failed(Throwable $e): void
    {
        $run = CrmAutomationRun::find($this->runId);

        if ($run && $run->status === AutomationRunStatus::Queued) {
            $this->finish($run, AutomationRunStatus::Failed, [], 'error');

            if ($automation = CrmAutomation::find($run->automation_id)) {
                $this->tally($automation, AutomationRunStatus::Failed);
            }
        }
    }

    /** @param  list<array<string, mixed>>  $results */
    private function finish(CrmAutomationRun $run, AutomationRunStatus $status, array $results, ?string $reason = null): void
    {
        $run->forceFill([
            'status' => $status->value,
            'results' => $results === [] ? null : $results,
            'error' => $reason,
            'finished_at' => now(),
        ])->save();
    }

    private function tally(CrmAutomation $automation, AutomationRunStatus $status): void
    {
        $failed = $status === AutomationRunStatus::Failed;
        $streak = $failed ? $automation->consecutive_failures + 1 : 0;

        $changes = [
            'runs_count' => $automation->runs_count + 1,
            'failures_count' => $automation->failures_count + ($failed || $status === AutomationRunStatus::Partial ? 1 : 0),
            'consecutive_failures' => $streak,
            'last_run_at' => now(),
        ];

        if ($streak >= self::PAUSE_AFTER_FAILURES) {
            $changes += ['is_active' => false, 'paused_reason' => 'repeated_failures'];
        }

        CrmAutomation::query()->whereKey($automation->id)->update($changes);
    }
}
