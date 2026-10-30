<?php

namespace App\Services\Crm;

use App\Enums\Crm\LeadStatus;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Bulk actions on CRM lists. Every row goes through the same service a single
 * edit would — timeline, scoring, lifecycle and notifications included — so a
 * bulk change is forty ordinary changes, not a shortcut around them. A row
 * that cannot take the change (a converted lead, a customer with channel
 * contacts asked to be deleted) is skipped with the reason, never failed for
 * the rest. Assignment notices are folded into one per person.
 */
final class CrmBulk
{
    public const MAX_IDS = 500;

    public const MAX_MATCHING = 5000;

    public const CUSTOMER_ACTIONS = ['set_status', 'set_owner', 'set_company', 'add_tags', 'remove_tags', 'delete'];

    public const LEAD_ACTIONS = ['set_status', 'set_owner', 'delete'];

    public const DEAL_ACTIONS = ['set_owner', 'move_stage', 'delete'];

    public const TASK_ACTIONS = ['complete', 'reopen', 'set_assignee', 'delete'];

    public function __construct(
        private readonly CustomerService $customers,
        private readonly LeadService $leads,
        private readonly DealService $deals,
        private readonly TaskService $tasks,
    ) {}

    /** @param  Collection<int, Customer>  $rows */
    public function customers(User $actor, Collection $rows, string $action, mixed $value): array
    {
        $tagIds = in_array($action, ['add_tags', 'remove_tags'], true) ? $this->tagIds($actor, $value) : [];
        $fields = match ($action) {
            'set_status' => $this->customers->validate($actor->tenant_id, ['status' => $value], creating: false),
            'set_owner' => $this->customers->validate($actor->tenant_id, ['owner_user_id' => $value ?: null], creating: false),
            'set_company' => $this->customers->validate($actor->tenant_id, ['company_id' => $value ?: null], creating: false),
            default => [],
        };

        return $this->each($rows, function (Customer $customer) use ($actor, $action, $fields, $tagIds) {
            match ($action) {
                'set_status', 'set_owner', 'set_company' => $this->customers->update($customer, $actor, $fields),
                'add_tags', 'remove_tags' => $this->retag($customer, $actor, $action, $tagIds),
                'delete' => $this->customers->delete($customer),
            };
        });
    }

    /** @param  Collection<int, Lead>  $rows */
    public function leads(User $actor, Collection $rows, string $action, mixed $value): array
    {
        $fields = match ($action) {
            'set_status' => $this->leads->validate($actor->tenant_id, ['status' => $value], creating: false),
            'set_owner' => $this->leads->validate($actor->tenant_id, ['owner_user_id' => $value ?: null], creating: false),
            default => [],
        };

        return $this->each($rows, function (Lead $lead) use ($actor, $action, $fields) {
            if ($action === 'delete') {
                $this->leads->delete($lead, $actor);

                return;
            }

            if ($lead->status === LeadStatus::Converted && $action === 'set_status') {
                throw ValidationException::withMessages(['status' => __('crm.leads.converted_locked')]);
            }

            $this->leads->update($lead, $actor, $fields);
        });
    }

    /** @param  Collection<int, Deal>  $rows */
    public function deals(User $actor, Collection $rows, string $action, mixed $value, ?string $lostReason = null): array
    {
        $fields = match ($action) {
            'set_owner' => $this->deals->validate($actor->tenant_id, ['owner_user_id' => $value ?: null], creating: false),
            default => [],
        };

        return $this->each($rows, function (Deal $deal) use ($actor, $action, $fields, $value, $lostReason) {
            match ($action) {
                'move_stage' => $this->deals->move($deal, $actor, (int) $value, null, $lostReason),
                'delete' => $this->deals->delete($deal, $actor),
                default => $this->deals->update($deal, $actor, $fields),
            };
        });
    }

    /** @param  Collection<int, CrmTask>  $rows */
    public function tasks(User $actor, Collection $rows, string $action, mixed $value): array
    {
        $fields = $action === 'set_assignee'
            ? $this->tasks->validate($actor->tenant_id, ['assignee_user_id' => $value ?: null], creating: false)
            : [];

        return $this->each($rows, function (CrmTask $task) use ($actor, $action, $fields) {
            match ($action) {
                'complete' => $this->tasks->complete($task, $actor),
                'reopen' => $this->tasks->reopen($task),
                'delete' => $this->tasks->delete($task),
                default => $this->tasks->update($task, $actor, $fields),
            };
        });
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array{updated: int, skipped: int, problems: list<array{id: int, message: string}>}
     */
    private function each(Collection $rows, callable $apply): array
    {
        return CrmNotifier::batch(function () use ($rows, $apply) {
            $result = ['updated' => 0, 'skipped' => 0, 'problems' => []];

            foreach ($rows as $row) {
                try {
                    $apply($row);
                    $result['updated']++;
                } catch (ValidationException $e) {
                    $this->skip($result, $row->id, (string) collect($e->errors())->flatten()->first());
                } catch (HttpException $e) {
                    $this->skip($result, $row->id, $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                    $this->skip($result, $row->id, __('crm.bulk.row_failed'));
                }
            }

            return $result;
        });
    }

    private function skip(array &$result, int $id, string $message): void
    {
        $result['skipped']++;

        // Enough to say why; a thousand identical lines say nothing more.
        if (count($result['problems']) < 20) {
            $result['problems'][] = ['id' => $id, 'message' => $message];
        }
    }

    /** @param  list<int>  $tagIds */
    private function retag(Customer $customer, User $actor, string $action, array $tagIds): void
    {
        $current = \DB::table('contact_tags')
            ->join('contacts', 'contacts.id', '=', 'contact_tags.contact_id')
            ->where('contacts.customer_id', $customer->id)
            ->distinct()->pluck('contact_tags.tag_id')->map(fn ($id) => (int) $id)->all();

        $next = $action === 'add_tags'
            ? array_values(array_unique([...$current, ...$tagIds]))
            : array_values(array_diff($current, $tagIds));

        sort($current);
        sort($next);

        if ($current !== $next) {
            $this->customers->syncTags($customer, $actor, $next);
        }
    }

    /** @return list<int> tags of this workspace */
    private function tagIds(User $actor, mixed $value): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $value, 'is_numeric'))));

        if ($ids === [] || \DB::table('tags')->where('tenant_id', $actor->tenant_id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['value' => __('crm.bulk.invalid_tags')]);
        }

        return $ids;
    }
}
