<?php

namespace App\Services\Crm;

use App\Events\CrmChanged;
use App\Events\CrmNotice;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling people about CRM work: a toast on the dashboard (CrmNotice, on the
 * person's own channel) and a browser push, for a lead or deal given to them
 * and for a task reminder; and a nudge to every open board when something
 * moved (CrmChanged, ids only). None of it may fail the change it announces.
 */
class CrmNotifier
{
    /**
     * While a bulk action runs: assignments counted per person and entity, so
     * someone given forty leads gets one notice, not forty.
     *
     * @var array<int, array{user: User, by: ?User, counts: array<string, int>}>|null
     */
    private static ?array $batch = null;

    /** Run `$work` with assignment notices folded into one per assignee. */
    public static function batch(callable $work): mixed
    {
        $outer = self::$batch;
        self::$batch = [];

        try {
            return $work();
        } finally {
            $collected = self::$batch;
            self::$batch = $outer;

            foreach ($collected ?? [] as $entry) {
                self::assignedMany($entry['user'], $entry['by'], $entry['counts']);
            }
        }
    }

    /** A lead, deal or task was given to `$assignee` by someone else. */
    public static function assigned(Lead|Deal|CrmTask $record, User $assignee, ?User $by): void
    {
        if ($by && $by->id === $assignee->id) {
            return;
        }

        $kind = match (true) {
            $record instanceof Lead => 'lead',
            $record instanceof Deal => 'deal',
            default => 'task',
        };

        if (self::$batch !== null) {
            self::$batch[$assignee->id] ??= ['user' => $assignee, 'by' => $by, 'counts' => []];
            self::$batch[$assignee->id]['counts'][$kind] = (self::$batch[$assignee->id]['counts'][$kind] ?? 0) + 1;

            return;
        }

        $url = $kind === 'task' ? '/tasks' : "/{$kind}s/{$record->id}";

        self::safely(function () use ($record, $assignee, $by, $kind, $url) {
            broadcast(new CrmNotice($assignee->id, 'assigned', [
                'entity' => $kind,
                'id' => $record->id,
                'title' => $record->title,
                'by' => $by?->name,
                'url' => $url,
            ]));

            PushNotifier::queueForUser($assignee, 'crm_assigned', $record->title, ['agent' => $by?->name ?? 'Pingly'], $url);
        });
    }

    /** @param  array<string, int>  $counts  entity => how many */
    private static function assignedMany(User $assignee, ?User $by, array $counts): void
    {
        foreach ($counts as $kind => $count) {
            $url = $kind === 'task' ? '/tasks' : "/{$kind}s";
            $title = trans_choice("crm.notices.assigned_many.{$kind}", $count, ['count' => $count], $assignee->localeCode());

            self::safely(function () use ($assignee, $by, $kind, $count, $url, $title) {
                broadcast(new CrmNotice($assignee->id, 'assigned', [
                    'entity' => $kind,
                    'count' => $count,
                    'title' => $title,
                    'by' => $by?->name,
                    'url' => $url,
                ]));

                PushNotifier::queueForUser($assignee, 'crm_assigned', $title, ['agent' => $by?->name ?? 'Pingly'], $url);
            });
        }
    }

    /** A task's reminder time has come. */
    public static function taskReminder(CrmTask $task): void
    {
        $assignee = $task->assignee;

        if (! $assignee) {
            return;
        }

        $when = $task->due_at
            ? $task->due_at->copy()->setTimezone($assignee->tenant?->displayTimezone() ?? config('app.timezone'))->locale($assignee->localeCode())->translatedFormat('D j M, H:i')
            : '';

        self::safely(function () use ($task, $assignee, $when) {
            broadcast(new CrmNotice($assignee->id, 'task_reminder', [
                'entity' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'due_at' => $task->due_at?->toIso8601String(),
                'url' => '/tasks',
            ]));

            PushNotifier::queueForUser($assignee, 'crm_task_due', $task->title, ['when' => $when], '/tasks');
        });
    }

    /**
     * A CRM automation telling someone about a record: the sentence its author
     * wrote, the record's title, and a link to it.
     */
    public static function automationNotice(User $user, string $automation, string $title, string $message, string $url): void
    {
        self::safely(function () use ($user, $automation, $title, $message, $url) {
            broadcast(new CrmNotice($user->id, 'automation', [
                'automation' => $automation,
                'title' => $title,
                'message' => $message,
                'url' => $url,
            ]));

            PushNotifier::queueForUser($user, 'crm_automation', $title, ['message' => $message], $url);
        });
    }

    public static function changed(Lead|Deal|CrmTask $record): void
    {
        $entity = match (true) {
            $record instanceof Lead => 'lead',
            $record instanceof Deal => 'deal',
            default => 'task',
        };

        self::safely(fn () => broadcast(new CrmChanged(
            (int) $record->tenant_id,
            $entity,
            (int) $record->id,
            $record instanceof Deal ? (int) $record->pipeline_id : null,
            $record->customer_id !== null ? (int) $record->customer_id : null,
        )));
    }

    private static function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('CRM notification could not be sent', ['error' => $e->getMessage()]);
        }
    }
}
