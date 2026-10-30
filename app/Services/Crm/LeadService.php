<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Enums\Crm\LeadStatus;
use App\Models\CrmActivity;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leads: someone who might buy, worked from first contact to qualified — then
 * converted into a deal, or set aside as disqualified.
 */
class LeadService
{
    private const TRACKED = ['title', 'score', 'source', 'value_cents', 'owner_user_id'];

    public function __construct(
        private readonly DealService $deals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, array $input, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];
        // Converted is reached by converting, never set.
        $settable = array_values(array_diff(LeadStatus::values(), [LeadStatus::Converted->value]));

        return validator($input, [
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:5000'],
            'status' => [...$sometimes, 'nullable', Rule::in($settable)],
            'score' => [...$sometimes, 'nullable', 'integer', 'min:0', 'max:100'],
            'source' => [...$sometimes, 'nullable', 'string', 'max:40'],
            'value_cents' => [...$sometimes, 'nullable', 'integer', 'min:0', 'max:1000000000000000'],
            'owner_user_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'disqualified_reason' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:255'],
        ])->validate();
    }

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data): Lead
    {
        $conversation = CrmSubjects::conversation($actor, $data['conversation_id'] ?? null);
        $customer = $this->deals->customerFor($actor, $data, $conversation);

        return $this->persist($actor->tenant_id, $customer, $conversation, $data, $actor);
    }

    /**
     * A lead about the person in a conversation, written by a flow: nobody is
     * in charge of it until someone (or an automation) gives it to them.
     *
     * @param  array<string, mixed>  $data  title, value_cents
     */
    public function createForConversation(\App\Models\Conversation $conversation, array $data): Lead
    {
        $customer = CrmSubjects::customerOfConversation($conversation);

        return $this->persist((int) $conversation->connection->tenant_id, $customer, $conversation, ['owner_user_id' => null] + $data, null);
    }

    /** @param  array<string, mixed>  $data  validated */
    private function persist(int $tenantId, \App\Models\Customer $customer, ?\App\Models\Conversation $conversation, array $data, ?User $actor): Lead
    {
        $automatic = LeadScoring::enabled($tenantId);

        $lead = DB::transaction(function () use ($tenantId, $actor, $data, $customer, $conversation, $automatic) {
            $lead = Lead::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? LeadStatus::New->value,
                // With scoring on, the number is computed; one typed in is ignored.
                'score' => $automatic ? 0 : (int) ($data['score'] ?? 0),
                // Where they came from: the channel of the conversation it was
                // started from, else what the customer record says.
                'source' => $data['source'] ?? $conversation?->contact?->channel?->value ?? $customer->source,
                'value_cents' => $data['value_cents'] ?? null,
                'owner_user_id' => array_key_exists('owner_user_id', $data) ? $data['owner_user_id'] : $actor?->id,
                'conversation_id' => $conversation?->id,
                'disqualified_reason' => $data['disqualified_reason'] ?? null,
            ]);

            CrmTimeline::record($lead, CrmTimeline::CREATED, $actor, [
                'entity' => 'lead',
                'title' => $lead->title,
                'source' => $lead->source,
            ], $lead->conversation_id);

            CustomerLifecycle::promote($customer, CustomerStatus::Prospect, $actor);

            return $lead;
        });

        if ($automatic) {
            LeadScoring::apply($lead->load('customer'));
        }

        if ($lead->owner_user_id && ($owner = User::find($lead->owner_user_id))) {
            CrmNotifier::assigned($lead, $owner, $actor);
        }

        CrmNotifier::changed($lead);

        return $lead->fresh(['customer', 'owner']);
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @param  ?User  $actor  null when an automation made the change
     */
    public function update(Lead $lead, ?User $actor, array $data): Lead
    {
        if ($lead->status === LeadStatus::Converted && isset($data['status'])) {
            throw ValidationException::withMessages(['status' => __('crm.leads.converted_locked')]);
        }

        $automatic = LeadScoring::enabled($lead->tenant_id);

        if ($automatic) {
            unset($data['score']);
        }

        $before = $this->snapshot($lead);
        $previousStatus = $lead->status;
        $previousOwner = $lead->owner_user_id;

        $lead->fill(collect($data)->only([...self::TRACKED, 'description', 'status', 'disqualified_reason'])->all());

        if ($lead->status !== LeadStatus::Disqualified) {
            $lead->disqualified_reason = null;
        }

        $lead->save();

        if ($automatic) {
            LeadScoring::apply($lead->load('customer'));
        }

        $changes = CrmTimeline::diff($before, $this->snapshot($lead));
        $this->nameChanges($changes);

        if ($changes !== []) {
            CrmTimeline::record($lead, CrmTimeline::UPDATED, $actor, ['changes' => $changes, 'entity' => 'lead', 'title' => $lead->title]);
        }

        if ($lead->status !== $previousStatus) {
            CrmTimeline::record($lead, CrmTimeline::LEAD_STATUS_CHANGED, $actor, array_filter([
                'entity' => 'lead',
                'title' => $lead->title,
                'from' => $previousStatus?->value,
                'to' => $lead->status->value,
                'reason' => $lead->disqualified_reason,
            ], fn ($value) => $value !== null));
        }

        if ($lead->owner_user_id && $lead->owner_user_id !== $previousOwner && ($owner = User::find($lead->owner_user_id))) {
            CrmNotifier::assigned($lead, $owner, $actor);
        }

        CrmNotifier::changed($lead);

        return $lead->fresh(['customer', 'owner']);
    }

    /**
     * Turn a lead into a deal. Who works it, its value and where it
     * came from carry over; the lead closes as Converted and points at it.
     *
     * @param  array<string, mixed>  $input  pipeline_id, stage_id, title, value_cents, expected_close_date
     */
    public function convert(Lead $lead, User $actor, array $input): Deal
    {
        if (! $lead->status->isOpen()) {
            throw ValidationException::withMessages(['lead' => __('crm.leads.not_convertible')]);
        }

        $data = $this->deals->validate($actor->tenant_id, array_merge([
            'title' => $lead->title,
            'value_cents' => $lead->value_cents,
            'owner_user_id' => $lead->owner_user_id,
            'description' => $lead->description,
        ], array_filter($input, fn ($value) => $value !== null)), creating: true);

        $data['customer_id'] = $lead->customer_id;
        unset($data['conversation_id'], $data['customer']);

        return DB::transaction(function () use ($lead, $actor, $data) {
            $deal = $this->deals->create($actor, $data, ['lead_id' => $lead->id, 'conversation_id' => $lead->conversation_id]);

            $lead->forceFill([
                'status' => LeadStatus::Converted,
                'converted_deal_id' => $deal->id,
                'converted_at' => now(),
            ])->save();

            CrmTimeline::record($lead, CrmTimeline::LEAD_CONVERTED, $actor, [
                'entity' => 'lead',
                'title' => $lead->title,
                'deal_id' => $deal->id,
                'deal_title' => $deal->title,
            ]);

            CrmNotifier::changed($lead);

            return $deal;
        });
    }

    public function delete(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            if ($lead->customer) {
                CrmTimeline::record($lead->customer, CrmTimeline::DELETED, $actor, ['entity' => 'lead', 'title' => $lead->title]);
            }

            CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->delete();
            CrmTask::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)
                ->update(['subject_type' => 'customer', 'subject_id' => $lead->customer_id]);
            $lead->delete();
        });

        CrmNotifier::changed($lead);
    }

    /** @return array<string, mixed> */
    private function snapshot(Lead $lead): array
    {
        return collect(self::TRACKED)->mapWithKeys(fn ($field) => [$field => $lead->getAttribute($field)])->all();
    }

    /** @param  array<string, array<string, mixed>>  $changes */
    private function nameChanges(array &$changes): void
    {
        foreach (['owner_user_id' => User::class] as $field => $model) {
            if (isset($changes[$field])) {
                $changes[$field]['from_name'] = $changes[$field]['from'] ? $model::find($changes[$field]['from'])?->name : null;
                $changes[$field]['to_name'] = $changes[$field]['to'] ? $model::find($changes[$field]['to'])?->name : null;
            }
        }
    }
}
