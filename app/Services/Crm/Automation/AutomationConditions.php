<?php

namespace App\Services\Crm\Automation;

use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Whether a record meets an automation's conditions — read as it stands when
 * the run happens, not as it stood when the trigger fired: a lead given to
 * someone in the second between the two no longer "has nobody in charge".
 */
final class AutomationConditions
{
    /**
     * @param  list<array<string, mixed>>  $conditions  normalised by AutomationDefinition
     */
    public static function matches(Model $subject, string $match, array $conditions): bool
    {
        if ($conditions === []) {
            return true;
        }

        $customer = AutomationSubjects::customerOf($subject);

        foreach ($conditions as $condition) {
            $met = self::check($subject, $customer, $condition);

            if ($match === 'any' && $met) {
                return true;
            }

            if ($match !== 'any' && ! $met) {
                return false;
            }
        }

        return $match !== 'any';
    }

    /** @param  array<string, mixed>  $condition */
    private static function check(Model $subject, ?Customer $customer, array $condition): bool
    {
        $operator = $condition['operator'];
        $value = $condition['value'] ?? null;

        $actual = match ($condition['field']) {
            'lead_status' => $subject instanceof Lead ? $subject->status?->value : null,
            'lead_source' => $subject instanceof Lead ? $subject->source : null,
            'lead_score' => $subject instanceof Lead ? (int) $subject->score : null,
            'value' => ($subject instanceof Lead || $subject instanceof Deal) ? $subject->value_cents : null,
            'pipeline' => $subject instanceof Deal ? $subject->pipeline_id : null,
            'stage' => $subject instanceof Deal ? $subject->stage_id : null,
            'deal_status' => $subject instanceof Deal ? $subject->status : null,
            'owner' => match (true) {
                $subject instanceof CrmTask => $subject->assignee_user_id,
                default => $subject->getAttribute('owner_user_id'),
            },
            'task_priority' => $subject instanceof CrmTask ? $subject->priority?->value : null,
            'customer_status' => $customer?->status?->value,
            'customer_source' => $customer?->source,
            'customer_tags' => $customer ? self::tagIds($customer) : [],
            'customer_company' => $customer?->company_id,
            default => null,
        };

        return match ($operator) {
            'in' => in_array((string) $actual, array_map('strval', (array) $value), true),
            'not_in' => ! in_array((string) $actual, array_map('strval', (array) $value), true),
            'is' => $actual !== null && (int) $actual === (int) $value,
            'is_not' => $actual === null || (int) $actual !== (int) $value,
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            'at_least' => $actual !== null && (int) $actual >= (int) $value,
            'below' => $actual !== null && (int) $actual < (int) $value,
            'has_any' => array_intersect(array_map('intval', (array) $value), (array) $actual) !== [],
            'has_none' => array_intersect(array_map('intval', (array) $value), (array) $actual) === [],
            default => false,
        };
    }

    /** @return list<int> the tags on any of the person's channel contacts */
    public static function tagIds(Customer $customer): array
    {
        return DB::table('contact_tags')
            ->join('contacts', 'contacts.id', '=', 'contact_tags.contact_id')
            ->where('contacts.customer_id', $customer->id)
            ->distinct()->pluck('contact_tags.tag_id')->map(fn ($id) => (int) $id)->all();
    }
}
