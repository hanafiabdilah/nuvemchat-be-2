<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way an entry reaches a CRM timeline.
 *
 * Entries carry what they need to be read later — names as they were, not
 * just ids — because the thing they mention may be renamed or gone by then:
 * "merged with Budi" has to stay readable after Budi's record is deleted by
 * that very merge.
 */
class CrmTimeline
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const CONTACT_LINKED = 'contact_linked';

    public const CONTACT_UNLINKED = 'contact_unlinked';

    public const MERGED = 'merged';

    public const RELATIONSHIP_ADDED = 'relationship_added';

    public const RELATIONSHIP_REMOVED = 'relationship_removed';

    public const TAGS_CHANGED = 'tags_changed';

    // Phase 2: the sales side.
    public const STAGE_CHANGED = 'stage_changed';

    public const DEAL_WON = 'deal_won';

    public const DEAL_LOST = 'deal_lost';

    public const DEAL_REOPENED = 'deal_reopened';

    public const LEAD_STATUS_CHANGED = 'lead_status_changed';

    public const LEAD_CONVERTED = 'lead_converted';

    public const ASSIGNED = 'assigned';

    public const TASK_COMPLETED = 'task_completed';

    public const DELETED = 'deleted';

    /**
     * Who is acting when no person is: entries written while an automation
     * or a flow runs say so (`data.automation` / `data.flow`), because "status
     * changed" with nobody's name on it reads like a bug.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $attribution = null;

    /** Run `$work` with every entry it writes attributed to `$attribution`. */
    public static function attributing(array $attribution, callable $work): mixed
    {
        $outer = self::$attribution;
        self::$attribution = $attribution;

        try {
            return $work();
        } finally {
            self::$attribution = $outer;
        }
    }

    /**
     * Every entry also names the person it is about (`customer_id`): an entry
     * on a lead or a deal is on its customer's timeline too, so that timeline
     * is one query rather than a union of every subject.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(
        Model $subject,
        string $type,
        ?User $user = null,
        array $data = [],
        ?int $conversationId = null,
        ?string $body = null,
        ?\DateTimeInterface $occurredAt = null,
    ): CrmActivity {
        if (self::$attribution !== null && $user === null) {
            $data += self::$attribution;
        }

        return CrmActivity::create([
            'tenant_id' => $subject->getAttribute('tenant_id'),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'customer_id' => self::customerIdOf($subject),
            'type' => $type,
            'body' => $body,
            'user_id' => $user?->id,
            'conversation_id' => $conversationId,
            'data' => $data === [] ? null : $data,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /** The person a subject is about, when there is one. */
    public static function customerIdOf(Model $subject): ?int
    {
        if ($subject instanceof Customer) {
            return $subject->id;
        }

        $id = $subject->getAttribute('customer_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * What changed between two snapshots of the tracked fields, as
     * `{field: {from, to}}` — empty when nothing did.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        // Both sides' keys: a custom field that was cleared is only in $before.
        foreach (array_keys($before + $after) as $field) {
            $old = $before[$field] ?? null;
            $value = $after[$field] ?? null;

            if ($old !== $value && ! ($old === '' && $value === null) && ! ($old === null && $value === '')) {
                $changes[$field] = ['from' => $old, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * Move every entry of one subject to another — a merge keeps its history.
     * For customers, the entries about their leads and deals follow too.
     */
    public static function move(Model $from, Model $to): void
    {
        CrmActivity::query()
            ->where('subject_type', $from->getMorphClass())
            ->where('subject_id', $from->getKey())
            ->update(['subject_type' => $to->getMorphClass(), 'subject_id' => $to->getKey()]);

        if ($from instanceof Customer && $to instanceof Customer) {
            CrmActivity::query()->where('customer_id', $from->id)->update(['customer_id' => $to->id]);
        }
    }
}
