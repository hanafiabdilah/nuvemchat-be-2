<?php

namespace App\Http\Resources\Crm;

use App\Models\CrmActivity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;

/**
 * The shapes the CRM pages read. One place, because a lead, a deal and a task
 * are drawn in several places (their own pages, Customer 360, the inbox card)
 * and must look the same in all of them.
 */
class CrmPresenter
{
    /** @return array<string, mixed>|null */
    public static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url] : null;
    }

    /** @return array<string, mixed> */
    public static function lead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'title' => $lead->title,
            'description' => $lead->description,
            'status' => $lead->status?->value,
            'score' => (int) $lead->score,
            // Which scoring rules made the score — empty when people type it.
            'score_details' => $lead->score_details ?? [],
            'score_updated_at' => $lead->score_updated_at?->toIso8601String(),
            'source' => $lead->source,
            'value_cents' => $lead->value_cents,
            'customer' => $lead->relationLoaded('customer') && $lead->customer
                ? ['id' => $lead->customer->id, 'name' => $lead->customer->name, 'status' => $lead->customer->status?->value]
                : null,
            'owner' => self::person($lead->relationLoaded('owner') ? $lead->owner : null),
            'conversation_id' => $lead->conversation_id,
            'disqualified_reason' => $lead->disqualified_reason,
            'converted_deal_id' => $lead->converted_deal_id,
            'converted_at' => $lead->converted_at?->toIso8601String(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function deal(Deal $deal): array
    {
        $stage = $deal->relationLoaded('stage') ? $deal->stage : null;

        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'description' => $deal->description,
            'value_cents' => (int) $deal->value_cents,
            'currency' => $deal->currency,
            'status' => $deal->status,
            'pipeline_id' => $deal->pipeline_id,
            'pipeline' => $deal->relationLoaded('pipeline') && $deal->pipeline ? ['id' => $deal->pipeline->id, 'name' => $deal->pipeline->name] : null,
            'stage_id' => $deal->stage_id,
            'stage' => $stage ? self::stage($stage) : null,
            'position' => (int) $deal->position,
            'expected_close_date' => $deal->expected_close_date?->format('Y-m-d'),
            'customer' => $deal->relationLoaded('customer') && $deal->customer
                ? ['id' => $deal->customer->id, 'name' => $deal->customer->name, 'status' => $deal->customer->status?->value]
                : null,
            'company' => $deal->relationLoaded('company') && $deal->company ? ['id' => $deal->company->id, 'name' => $deal->company->name] : null,
            'owner' => self::person($deal->relationLoaded('owner') ? $deal->owner : null),
            'lead_id' => $deal->lead_id,
            'conversation_id' => $deal->conversation_id,
            'stage_entered_at' => $deal->stage_entered_at?->toIso8601String(),
            'won_at' => $deal->won_at?->toIso8601String(),
            'lost_at' => $deal->lost_at?->toIso8601String(),
            'lost_reason' => $deal->lost_reason,
            'created_at' => $deal->created_at?->toIso8601String(),
            'updated_at' => $deal->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function stage(CrmPipelineStage $stage): array
    {
        return [
            'id' => $stage->id,
            'pipeline_id' => $stage->pipeline_id,
            'name' => $stage->name,
            'position' => (int) $stage->position,
            'probability' => (int) $stage->probability,
            'kind' => $stage->kind->value,
            'color' => $stage->color,
        ];
    }

    /** @return array<string, mixed> */
    public static function pipeline(CrmPipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'is_default' => (bool) $pipeline->is_default,
            'position' => (int) $pipeline->position,
            'stages' => $pipeline->relationLoaded('stages') ? $pipeline->stages->map(fn ($stage) => self::stage($stage))->values() : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function task(CrmTask $task): array
    {
        $subject = $task->relationLoaded('subject') ? $task->subject : null;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority?->value,
            'due_at' => $task->due_at?->toIso8601String(),
            'remind_at' => $task->remind_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'overdue' => $task->completed_at === null && $task->due_at !== null && $task->due_at->isPast(),
            'assignee' => self::person($task->relationLoaded('assignee') ? $task->assignee : null),
            'creator' => self::person($task->relationLoaded('creator') ? $task->creator : null),
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'subject_name' => $subject ? ($subject->title ?? $subject->name ?? null) : null,
            'customer' => $task->relationLoaded('customer') && $task->customer ? ['id' => $task->customer->id, 'name' => $task->customer->name] : null,
            'conversation_id' => $task->conversation_id,
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function activity(CrmActivity $activity): array
    {
        return [
            'id' => 'a-'.$activity->id,
            'activity_id' => $activity->id,
            'type' => $activity->type,
            'body' => $activity->body,
            'occurred_at' => $activity->occurred_at->toIso8601String(),
            'user' => $activity->relationLoaded('user') && $activity->user ? ['id' => $activity->user->id, 'name' => $activity->user->name] : null,
            'conversation_id' => $activity->conversation_id,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'customer_id' => $activity->customer_id,
            'data' => $activity->data ?? (object) [],
        ];
    }
}
