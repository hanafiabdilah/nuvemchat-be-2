<?php

namespace App\Services\Crm\Automation;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Crm\ActivityType;
use App\Enums\Crm\LeadStatus;
use App\Enums\Crm\StageKind;
use App\Models\Conversation;
use App\Models\CrmAutomation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Flow;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Conversation\SystemMessage;
use App\Services\Crm\CrmNotifier;
use App\Services\Crm\CrmTimeline;
use App\Services\Crm\CustomerService;
use App\Services\Crm\DealService;
use App\Services\Crm\LeadService;
use App\Services\Crm\TaskService;
use App\Services\Flow\FlowExecutor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * What an automation does to a record, one action at a time.
 *
 * Every change goes through the same services a person's change does — so
 * the timeline, lead scoring, the customer lifecycle and the "given to you"
 * notices behave exactly as if someone had clicked — with no person as the
 * actor: the timeline says the automation did it.
 *
 * Each action stands alone. One that fails (a person who left, a flow that
 * was deleted) is recorded with its reason and the rest still run; one that
 * has nothing to do (the lead is already theirs) is recorded as skipped. A
 * result never carries a sentence written for the moment: it carries a code
 * the dashboard words in the reader's language, plus, for a refusal the
 * services worded, that sentence.
 */
final class AutomationActions
{
    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function __construct(
        private readonly LeadService $leads,
        private readonly DealService $deals,
        private readonly TaskService $tasks,
        private readonly CustomerService $customers,
    ) {}

    /**
     * @return list<array{type: string, status: string, code?: string, detail?: string}>
     */
    public function run(CrmAutomation $automation, Model $subject): array
    {
        $results = [];

        foreach ($automation->actions ?? [] as $action) {
            $type = (string) ($action['type'] ?? '');

            try {
                // The record as it is now: an earlier action may have changed it.
                $subject->refresh();
                [$status, $code, $detail] = $this->perform($automation, $subject, $action) + [null, null, null];
            } catch (ValidationException $e) {
                [$status, $code, $detail] = [self::FAILED, 'refused', (string) collect($e->errors())->flatten()->first()];
            } catch (HttpException $e) {
                [$status, $code, $detail] = [self::FAILED, 'refused', $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                Log::warning('CRM automation action failed', [
                    'automation_id' => $automation->id,
                    'action' => $type,
                    'error' => $e->getMessage(),
                ]);
                [$status, $code, $detail] = [self::FAILED, 'error', null];
            }

            $results[] = array_filter([
                'type' => $type,
                'status' => $status,
                'code' => $code,
                'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null,
            ], fn ($value) => $value !== null);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{0: string, 1?: ?string, 2?: ?string}
     */
    private function perform(CrmAutomation $automation, Model $subject, array $action): array
    {
        return match ($action['type'] ?? null) {
            'assign_owner' => $this->assignOwner($automation, $subject, $action),
            'set_lead_status' => $this->setLeadStatus($subject, $action),
            'move_deal_stage' => $this->moveDealStage($subject, $action),
            'create_task' => $this->createTask($automation, $subject, $action),
            'notify' => $this->notify($automation, $subject, $action),
            'add_customer_tags', 'remove_customer_tags' => $this->retag($subject, $action),
            'set_customer_status' => $this->setCustomerStatus($subject, $action),
            'start_flow' => $this->startFlow($automation, $subject, $action),
            'add_note' => $this->addNote($automation, $subject, $action),
            default => [self::FAILED, 'unknown_action'],
        };
    }

    /** @param  array<string, mixed>  $action */
    private function assignOwner(CrmAutomation $automation, Model $subject, array $action): array
    {
        $candidates = ($action['mode'] ?? 'user') === 'user'
            ? [(int) ($action['user_id'] ?? 0)]
            : array_map('intval', (array) ($action['user_ids'] ?? []));

        $people = User::query()->where('tenant_id', $subject->getAttribute('tenant_id'))->whereIn('id', $candidates)->pluck('id')->map(fn ($id) => (int) $id)->all();
        // In the order the author listed them; whoever left is passed over.
        $people = array_values(array_filter($candidates, fn ($id) => in_array($id, $people, true)));

        if ($people === []) {
            return [self::FAILED, 'user_missing'];
        }

        $picked = match ($action['mode'] ?? 'user') {
            'round_robin' => $this->nextInTurn($automation, $people),
            'least_busy' => $this->leastBusy($subject, $people),
            default => $people[0],
        };

        if (AutomationSubjects::ownerIdOf($subject) === $picked) {
            return [self::SKIPPED, 'already_set'];
        }

        match (true) {
            $subject instanceof Lead => $this->leads->update($subject, null, ['owner_user_id' => $picked]),
            $subject instanceof Deal => $this->deals->update($subject, null, ['owner_user_id' => $picked]),
            $subject instanceof CrmTask => $this->tasks->update($subject, null, ['assignee_user_id' => $picked]),
            $subject instanceof Customer => $this->customers->update($subject, null, ['owner_user_id' => $picked]),
        };

        return [self::DONE, null, User::find($picked)?->name];
    }

    /**
     * Whoever comes after the last one this automation picked. The rotation
     * lives on the automation (one turn order per rule) and is advanced under
     * a lock, so two records arriving together go to two people.
     *
     * @param  list<int>  $people
     */
    private function nextInTurn(CrmAutomation $automation, array $people): int
    {
        return Cache::lock("crm-automation-turn:{$automation->id}", 10)->block(5, function () use ($automation, $people) {
            $state = CrmAutomation::query()->whereKey($automation->id)->value('state');
            $state = is_string($state) ? (json_decode($state, true) ?: []) : (array) $state;
            $last = (int) ($state['last_assigned'] ?? 0);

            $position = array_search($last, $people, true);
            $picked = $position === false ? $people[0] : $people[($position + 1) % count($people)];

            $state['last_assigned'] = $picked;
            CrmAutomation::query()->whereKey($automation->id)->update(['state' => json_encode($state)]);

            return $picked;
        });
    }

    /**
     * Whoever has the fewest open records of this kind; the first listed
     * breaks a tie.
     *
     * @param  list<int>  $people
     */
    private function leastBusy(Model $subject, array $people): int
    {
        $counts = match (true) {
            $subject instanceof Lead => Lead::query()->whereIn('owner_user_id', $people)
                ->whereIn('status', array_values(array_filter(LeadStatus::values(), fn ($s) => LeadStatus::from($s)->isOpen()))),
            $subject instanceof Deal => Deal::query()->whereIn('owner_user_id', $people)->where('status', StageKind::Open->value),
            $subject instanceof CrmTask => CrmTask::query()->whereIn('assignee_user_id', $people)->whereNull('completed_at'),
            default => Customer::query()->whereIn('owner_user_id', $people),
        };

        $column = $subject instanceof CrmTask ? 'assignee_user_id' : 'owner_user_id';
        $load = $counts->selectRaw("{$column} as person, COUNT(*) as total")->groupBy($column)->pluck('total', 'person');

        $picked = $people[0];
        foreach ($people as $person) {
            if ((int) ($load[$person] ?? 0) < (int) ($load[$picked] ?? 0)) {
                $picked = $person;
            }
        }

        return $picked;
    }

    /** @param  array<string, mixed>  $action */
    private function setLeadStatus(Model $subject, array $action): array
    {
        /** @var Lead $subject */
        if ($subject->status === LeadStatus::Converted) {
            return [self::SKIPPED, 'lead_converted'];
        }

        if ($subject->status?->value === $action['status']) {
            return [self::SKIPPED, 'already_set'];
        }

        $this->leads->update($subject, null, ['status' => $action['status']]);

        return [self::DONE];
    }

    /** @param  array<string, mixed>  $action */
    private function moveDealStage(Model $subject, array $action): array
    {
        /** @var Deal $subject */
        if ((int) $subject->stage_id === (int) $action['stage_id']) {
            return [self::SKIPPED, 'already_set'];
        }

        $this->deals->move($subject, null, (int) $action['stage_id'], null, null);

        return [self::DONE];
    }

    /** @param  array<string, mixed>  $action */
    private function createTask(CrmAutomation $automation, Model $subject, array $action): array
    {
        $assignee = match ($action['assignee'] ?? 'owner') {
            'owner' => AutomationSubjects::ownerIdOf($subject),
            'user' => User::query()->where('tenant_id', $subject->getAttribute('tenant_id'))->whereKey($action['user_id'] ?? 0)->value('id'),
            default => null,
        };

        if (($action['assignee'] ?? 'owner') === 'user' && ! $assignee) {
            return [self::FAILED, 'user_missing'];
        }

        // A task about a task is about what that task was about.
        $about = $subject instanceof CrmTask ? ($subject->subject ?? AutomationSubjects::customerOf($subject)) : $subject;

        $this->tasks->createFor(
            (int) $subject->getAttribute('tenant_id'),
            $about,
            $subject->getAttribute('conversation_id') ? (int) $subject->getAttribute('conversation_id') : null,
            [
                'title' => $this->fill($automation, $subject, (string) $action['title']),
                'description' => isset($action['description']) ? $this->fill($automation, $subject, (string) $action['description']) : null,
                'priority' => $action['priority'] ?? null,
                'due_at' => $this->dueAt($subject, (int) ($action['due_in_days'] ?? 1)),
                'assignee_user_id' => $assignee,
            ],
            null,
        );

        return [self::DONE];
    }

    /**
     * Due at the end of the working day N days from now, in the workspace's
     * time; today's end already gone means in an hour.
     */
    private function dueAt(Model $subject, int $days): Carbon
    {
        $timezone = Tenant::find($subject->getAttribute('tenant_id'))?->displayTimezone() ?? config('app.timezone');
        $due = Carbon::now($timezone)->addDays($days)->setTime(17, 0);

        return $due->isPast() ? Carbon::now()->addHour() : $due->utc();
    }

    /** @param  array<string, mixed>  $action */
    private function notify(CrmAutomation $automation, Model $subject, array $action): array
    {
        $ids = [];
        $recipients = $action['recipients'] ?? 'owner';

        if (in_array($recipients, ['owner', 'owner_and_users'], true) && ($owner = AutomationSubjects::ownerIdOf($subject))) {
            $ids[] = $owner;
        }

        if ($recipients !== 'owner') {
            $ids = [...$ids, ...array_map('intval', (array) ($action['user_ids'] ?? []))];
        }

        $people = User::query()->where('tenant_id', $subject->getAttribute('tenant_id'))->whereIn('id', array_unique($ids))->get()
            // Nobody is told about a record they cannot open.
            ->filter(fn (User $user) => AutomationSubjects::visibleTo($subject, $user));

        if ($people->isEmpty()) {
            return [self::SKIPPED, $recipients === 'owner' ? 'no_owner' : 'no_recipients'];
        }

        $message = $this->fill($automation, $subject, (string) $action['message']);

        foreach ($people as $user) {
            CrmNotifier::automationNotice($user, $automation->name, AutomationSubjects::titleOf($subject), $message, AutomationSubjects::urlOf($subject));
        }

        return [self::DONE, null, $people->pluck('name')->implode(', ')];
    }

    /** @param  array<string, mixed>  $action */
    private function retag(Model $subject, array $action): array
    {
        $customer = AutomationSubjects::customerOf($subject);

        if (! $customer) {
            return [self::SKIPPED, 'no_customer'];
        }

        // Tags live on the person's channel contacts.
        if (! $customer->contacts()->exists()) {
            return [self::SKIPPED, 'no_contacts'];
        }

        $current = AutomationConditions::tagIds($customer);
        $wanted = array_map('intval', (array) ($action['tag_ids'] ?? []));
        $next = $action['type'] === 'add_customer_tags'
            ? array_values(array_unique([...$current, ...$wanted]))
            : array_values(array_diff($current, $wanted));

        sort($current);
        sort($next);

        if ($current === $next) {
            return [self::SKIPPED, 'already_set'];
        }

        $this->customers->syncTags($customer, null, $next);

        return [self::DONE];
    }

    /** @param  array<string, mixed>  $action */
    private function setCustomerStatus(Model $subject, array $action): array
    {
        $customer = AutomationSubjects::customerOf($subject);

        if (! $customer) {
            return [self::SKIPPED, 'no_customer'];
        }

        if ($customer->status?->value === $action['status']) {
            return [self::SKIPPED, 'already_set'];
        }

        $this->customers->update($customer, null, ['status' => $action['status']]);

        return [self::DONE];
    }

    /**
     * Start a flow in the person's open conversation — only one nobody is
     * holding: a flow never talks over an operator who has the thread, and an
     * automation does not take threads away from people.
     *
     * @param  array<string, mixed>  $action
     */
    private function startFlow(CrmAutomation $automation, Model $subject, array $action): array
    {
        $tenant = Tenant::find($subject->getAttribute('tenant_id'));

        if ($tenant && config('services.billing.enforce') && ! app(SubscriptionGate::class)->feature($tenant, 'flow')) {
            return [self::SKIPPED, 'flow_not_in_plan'];
        }

        $flow = Flow::query()->where('tenant_id', $subject->getAttribute('tenant_id'))->find($action['flow_id'] ?? 0);

        if (! $flow) {
            return [self::FAILED, 'flow_missing'];
        }

        $customer = AutomationSubjects::customerOf($subject);

        if (! $customer) {
            return [self::SKIPPED, 'no_customer'];
        }

        $conversation = Conversation::query()
            ->whereIn('contact_id', $customer->contacts()->select('id'))
            ->where('status', '!=', Status::Resolved->value)
            ->with(['contact', 'connection'])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->first();

        if (! $conversation || $conversation->isGroup()) {
            return [self::SKIPPED, 'no_open_conversation'];
        }

        if ($conversation->connection?->channel === Channel::Email) {
            return [self::SKIPPED, 'flow_not_supported'];
        }

        if ($conversation->status === Status::Active || $conversation->user_id) {
            return [self::SKIPPED, 'conversation_with_human'];
        }

        $lock = Cache::lock("conversation-flow:{$conversation->id}", 30);

        if (! $lock->get()) {
            return [self::SKIPPED, 'flow_busy'];
        }

        try {
            SystemMessage::info(
                $conversation,
                "The automation \"{$automation->name}\" started the flow \"{$flow->name}\".",
                'crm_automation_started_flow',
                ['automation' => $automation->name, 'flow' => $flow->name],
            );

            (new FlowExecutor)->triggerFlow($conversation, $flow);
        } finally {
            $lock->release();
        }

        return [self::DONE, null, $flow->name];
    }

    /** @param  array<string, mixed>  $action */
    private function addNote(CrmAutomation $automation, Model $subject, array $action): array
    {
        CrmTimeline::record($subject, ActivityType::Note->value, null, [], null, $this->fill($automation, $subject, (string) $action['body']));

        return [self::DONE];
    }

    /**
     * The words an author wrote, with the record filled in: {{title}},
     * {{customer}}, {{owner}}, {{stage}}, {{value}}, {{automation}}.
     */
    private function fill(CrmAutomation $automation, Model $subject, string $text): string
    {
        $customer = AutomationSubjects::customerOf($subject);
        $owner = ($id = AutomationSubjects::ownerIdOf($subject)) ? User::find($id)?->name : null;
        $value = $subject->getAttribute('value_cents');

        return strtr($text, [
            '{{title}}' => AutomationSubjects::titleOf($subject),
            '{{customer}}' => (string) $customer?->name,
            '{{owner}}' => (string) $owner,
            '{{stage}}' => $subject instanceof Deal ? (string) DB::table('crm_pipeline_stages')->where('id', $subject->stage_id)->value('name') : '',
            '{{value}}' => $value !== null ? Money::format((int) $value, $subject instanceof Deal ? $subject->currency : Tenant::find($subject->getAttribute('tenant_id'))?->currency()) : '',
            '{{automation}}' => $automation->name,
        ]);
    }
}
