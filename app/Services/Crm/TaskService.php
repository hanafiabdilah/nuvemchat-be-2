<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskPriority;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Follow-ups: what someone has to do, by when, about whom — with a reminder
 * sent to whoever it is assigned to (crm:task-reminders).
 */
class TaskService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, array $input, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        $data = validator($input, [
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:5000'],
            'priority' => [...$sometimes, 'nullable', Rule::in(TaskPriority::values())],
            'due_at' => [...$sometimes, 'nullable', 'date'],
            'remind_at' => [...$sometimes, 'nullable', 'date'],
            'assignee_user_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in(CrmSubjects::TYPES)],
            'subject_id' => ['nullable', 'required_with:subject_type', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
        ])->validate();

        if (! empty($data['due_at']) && ! empty($data['remind_at']) && Carbon::parse($data['remind_at'])->gt(Carbon::parse($data['due_at']))) {
            throw ValidationException::withMessages(['remind_at' => __('crm.tasks.remind_after_due')]);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data): CrmTask
    {
        $conversation = CrmSubjects::conversation($actor, $data['conversation_id'] ?? null);

        // Written from a conversation without a subject: it is about the
        // person in that conversation.
        $subject = ! empty($data['subject_type'])
            ? CrmSubjects::resolve($actor, $data['subject_type'], $data['subject_id'])
            : ($conversation ? CrmSubjects::customerOfConversation($conversation) : null);

        return $this->createFor($actor->tenant_id, $subject, $conversation?->id, $data, $actor);
    }

    /**
     * Write a task about a subject already resolved — by a person (who it is
     * then assigned to unless they say otherwise), or by an automation or a
     * flow (`$actor` null: nobody, unless an assignee is given).
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function createFor(int $tenantId, ?\Illuminate\Database\Eloquent\Model $subject, ?int $conversationId, array $data, ?User $actor): CrmTask
    {
        $task = CrmTask::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'due_at' => $data['due_at'] ?? null,
            'remind_at' => $data['remind_at'] ?? null,
            'assignee_user_id' => array_key_exists('assignee_user_id', $data) ? $data['assignee_user_id'] : $actor?->id,
            'created_by' => $actor?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'customer_id' => $subject ? CrmTimeline::customerIdOf($subject) : null,
            'conversation_id' => $conversationId,
        ]);

        if ($task->assignee_user_id && ($assignee = User::find($task->assignee_user_id))) {
            CrmNotifier::assigned($task, $assignee, $actor);
        }

        CrmNotifier::changed($task);

        return $task->fresh(['assignee', 'creator', 'customer']);
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(CrmTask $task, ?User $actor, array $data): CrmTask
    {
        $previousAssignee = $task->assignee_user_id;

        $task->fill(collect($data)->only(['title', 'description', 'priority', 'due_at', 'remind_at', 'assignee_user_id'])->all());

        // A new reminder time is a new reminder.
        if ($task->isDirty('remind_at')) {
            $task->reminded_at = null;
        }

        $task->save();

        if ($task->assignee_user_id && $task->assignee_user_id !== $previousAssignee && ($assignee = User::find($task->assignee_user_id))) {
            CrmNotifier::assigned($task, $assignee, $actor);
        }

        CrmNotifier::changed($task);

        return $task->fresh(['assignee', 'creator', 'customer']);
    }

    public function complete(CrmTask $task, User $actor): CrmTask
    {
        if ($task->completed_at) {
            return $task;
        }

        $task->forceFill(['completed_at' => now(), 'completed_by' => $actor->id])->save();

        if ($task->subject) {
            CrmTimeline::record($task->subject, CrmTimeline::TASK_COMPLETED, $actor, ['title' => $task->title, 'task_id' => $task->id], $task->conversation_id);
        }

        CrmNotifier::changed($task);

        return $task->fresh(['assignee', 'creator', 'customer']);
    }

    public function reopen(CrmTask $task): CrmTask
    {
        $task->forceFill(['completed_at' => null, 'completed_by' => null])->save();

        CrmNotifier::changed($task);

        return $task->fresh(['assignee', 'creator', 'customer']);
    }

    public function delete(CrmTask $task): void
    {
        $task->delete();

        CrmNotifier::changed($task);
    }
}
