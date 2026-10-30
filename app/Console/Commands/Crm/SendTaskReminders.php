<?php

namespace App\Console\Commands\Crm;

use App\Models\CrmTask;
use App\Services\Crm\CrmNotifier;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Remind people of their tasks: every task whose reminder time has come, not
 * done and not reminded yet, tells its assignee (dashboard toast + browser
 * push). Every minute. Stamped before telling, so an overlapping run never
 * reminds twice.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'crm:task-reminders';

    protected $description = 'Send CRM task reminders that are due';

    public function handle(): int
    {
        Heartbeat::ping('crm:task-reminders');

        $sent = 0;

        CrmTask::query()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->whereNull('reminded_at')
            ->whereNull('completed_at')
            ->whereNotNull('assignee_user_id')
            ->with('assignee.tenant')
            ->orderBy('remind_at')
            ->limit(500)
            ->get()
            ->each(function (CrmTask $task) use (&$sent) {
                $claimed = CrmTask::query()->whereKey($task->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);

                if ($claimed) {
                    CrmNotifier::taskReminder($task);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
