<?php

namespace App\Services\Crm;

use App\Enums\Crm\ActivityType;
use App\Models\CrmActivity;
use App\Models\User;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What people log themselves: notes, calls, meetings and e-mails, on a
 * customer, lead, deal or company — and, when written beside a conversation,
 * linked to it. Only these entries can be edited or deleted, and only by the
 * person who wrote them or the owner; the system's own entries are history.
 */
class ActivityService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        return validator($input, [
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(ActivityType::values())],
            'body' => [...$sometimes, 'required', 'string', 'max:10000'],
            'subject_type' => [$creating ? 'nullable' : 'prohibited', 'required_with:subject_id', Rule::in(CrmSubjects::TYPES)],
            'subject_id' => [$creating ? 'nullable' : 'prohibited', 'required_with:subject_type', 'integer'],
            'conversation_id' => [$creating ? 'nullable' : 'prohibited', 'integer'],
            'occurred_at' => [...$sometimes, 'nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'outcome' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'duration_minutes' => [...$sometimes, 'nullable', 'integer', 'min:0', 'max:1440'],
        ])->validate();
    }

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data): CrmActivity
    {
        $conversation = CrmSubjects::conversation($actor, $data['conversation_id'] ?? null);

        $subject = ! empty($data['subject_type'])
            ? CrmSubjects::resolve($actor, $data['subject_type'], $data['subject_id'])
            : ($conversation ? CrmSubjects::customerOfConversation($conversation) : null);

        if (! $subject) {
            throw \Illuminate\Validation\ValidationException::withMessages(['subject_id' => __('crm.subjects.required')]);
        }

        return CrmTimeline::record(
            $subject,
            $data['type'],
            $actor,
            $this->details($data),
            $conversation?->id,
            $data['body'],
            isset($data['occurred_at']) ? \Illuminate\Support\Carbon::parse($data['occurred_at']) : null,
        )->load('user:id,name');
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(CrmActivity $activity, User $actor, array $data): CrmActivity
    {
        $this->assertEditable($activity, $actor);

        $activity->fill(array_filter([
            'body' => $data['body'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? \Illuminate\Support\Carbon::parse($data['occurred_at']) : null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('outcome', $data) || array_key_exists('duration_minutes', $data)) {
            $activity->data = array_merge($activity->data ?? [], $this->details($data)) ?: null;
        }

        $activity->save();

        return $activity->load('user:id,name');
    }

    public function delete(CrmActivity $activity, User $actor): void
    {
        $this->assertEditable($activity, $actor);

        $activity->delete();
    }

    public static function isManual(CrmActivity $activity): bool
    {
        return in_array($activity->type, ActivityType::values(), true);
    }

    private function assertEditable(CrmActivity $activity, User $actor): void
    {
        if (! self::isManual($activity)) {
            throw new HttpException(422, __('crm.activities.system_entry'));
        }

        if ($activity->user_id !== $actor->id && ! $actor->canAccessAllConnections()) {
            throw new HttpException(403, __('crm.activities.not_yours'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return array_filter([
            'outcome' => $data['outcome'] ?? null,
            'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
