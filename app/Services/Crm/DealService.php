<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Enums\Crm\StageKind;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Deals: creating them (by hand, from a conversation, from a lead), editing
 * them, and moving them along their pipeline — where reaching a Won or Lost
 * stage is what closes them.
 */
class DealService
{
    /** The fields the timeline records changes to. */
    private const TRACKED = ['title', 'value_cents', 'expected_close_date', 'owner_user_id', 'company_id'];

    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, array $input, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        return validator($input, [
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:5000'],
            'customer_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'pipeline_id' => ['nullable', 'integer', Rule::exists('crm_pipelines', 'id')->where('tenant_id', $tenantId)],
            'stage_id' => ['nullable', 'integer', Rule::exists('crm_pipeline_stages', 'id')->where('tenant_id', $tenantId)],
            'value_cents' => [...$sometimes, 'nullable', 'integer', 'min:0', 'max:1000000000000000'],
            'expected_close_date' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'owner_user_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'company_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @param  array<string, mixed>  $extra  set by callers, not by requests (lead_id)
     */
    public function create(User $actor, array $data, array $extra = []): Deal
    {
        $conversation = CrmSubjects::conversation($actor, $data['conversation_id'] ?? null);
        $customer = $this->customerFor($actor, $data, $conversation);

        $pipeline = isset($data['pipeline_id'])
            ? CrmPipeline::query()->where('tenant_id', $actor->tenant_id)->findOrFail($data['pipeline_id'])
            : CrmPipelines::ensureDefault($actor->tenant_id);

        $stage = isset($data['stage_id'])
            ? $this->stageOf($pipeline, (int) $data['stage_id'])
            : CrmPipelines::firstOpenStage($pipeline);

        $deal = DB::transaction(function () use ($actor, $data, $extra, $customer, $pipeline, $stage, $conversation) {
            $kind = $stage->kind;

            $deal = Deal::create([
                'tenant_id' => $actor->tenant_id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'customer_id' => $customer->id,
                'company_id' => $data['company_id'] ?? $customer->company_id,
                'lead_id' => $extra['lead_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'value_cents' => (int) ($data['value_cents'] ?? 0),
                'currency' => $customer->tenant?->currency() ?? 'BRL',
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'status' => $kind->value,
                'owner_user_id' => array_key_exists('owner_user_id', $data) ? $data['owner_user_id'] : $actor->id,
                'conversation_id' => $conversation?->id ?? ($extra['conversation_id'] ?? null),
                'position' => $this->nextPosition($stage),
                'stage_entered_at' => now(),
                'won_at' => $kind === StageKind::Won ? now() : null,
                'lost_at' => $kind === StageKind::Lost ? now() : null,
                'lost_reason' => $kind === StageKind::Lost ? ($data['lost_reason'] ?? null) : null,
            ]);

            CrmTimeline::record($deal, CrmTimeline::CREATED, $actor, [
                'entity' => 'deal',
                'title' => $deal->title,
                'stage' => $stage->name,
                'stage_id' => $stage->id,
                'value_cents' => $deal->value_cents,
                'from_lead' => $deal->lead_id !== null,
            ], $deal->conversation_id);

            CustomerLifecycle::promote($customer, $kind === StageKind::Won ? CustomerStatus::Customer : CustomerStatus::Prospect, $actor);

            return $deal;
        });

        if ($deal->owner_user_id && ($owner = User::find($deal->owner_user_id))) {
            CrmNotifier::assigned($deal, $owner, $actor);
        }

        CrmNotifier::changed($deal);

        return $deal->fresh(['stage', 'pipeline', 'customer', 'owner', 'company']);
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(Deal $deal, ?User $actor, array $data): Deal
    {
        if (isset($data['stage_id']) && (int) $data['stage_id'] !== $deal->stage_id) {
            $deal = $this->move($deal, $actor, (int) $data['stage_id'], null, $data['lost_reason'] ?? null);
        }

        $before = $this->snapshot($deal);
        $previousOwner = $deal->owner_user_id;

        $deal->fill(collect($data)->only([...self::TRACKED, 'description'])->all());

        if (array_key_exists('lost_reason', $data) && $deal->status === StageKind::Lost->value) {
            $deal->lost_reason = $data['lost_reason'];
        }

        $deal->save();

        $changes = CrmTimeline::diff($before, $this->snapshot($deal));
        $this->nameChanges($changes);

        if ($changes !== []) {
            CrmTimeline::record($deal, CrmTimeline::UPDATED, $actor, ['changes' => $changes, 'entity' => 'deal', 'title' => $deal->title]);
        }

        if ($deal->owner_user_id && $deal->owner_user_id !== $previousOwner && ($owner = User::find($deal->owner_user_id))) {
            CrmNotifier::assigned($deal, $owner, $actor);
        }

        CrmNotifier::changed($deal);

        return $deal->fresh(['stage', 'pipeline', 'customer', 'owner', 'company']);
    }

    /**
     * Move a deal to a stage (of its pipeline or another), at `$position`
     * among that stage's deals (the end when null). Reaching a Won stage wins
     * it and makes its customer a Customer; reaching a Lost stage loses it,
     * with the reason given; leaving either reopens it.
     */
    public function move(Deal $deal, ?User $actor, int $stageId, ?int $position, ?string $lostReason): Deal
    {
        $stage = CrmPipelineStage::query()->where('tenant_id', $deal->tenant_id)->find($stageId);

        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => __('crm.deals.stage_not_found')]);
        }

        $from = $deal->stage;
        $wasStatus = $deal->status;

        DB::transaction(function () use ($deal, $actor, $stage, $position, $lostReason, $from, $wasStatus) {
            $kind = $stage->kind;
            $changedStage = $stage->id !== $deal->stage_id;

            $deal->forceFill([
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->id,
                'status' => $kind->value,
                'stage_entered_at' => $changedStage ? now() : $deal->stage_entered_at,
                'won_at' => $kind === StageKind::Won ? ($deal->won_at ?? now()) : null,
                'lost_at' => $kind === StageKind::Lost ? ($deal->lost_at ?? now()) : null,
                'lost_reason' => $kind === StageKind::Lost ? ($lostReason ?? $deal->lost_reason) : null,
            ])->save();

            $this->place($deal, $stage, $position);

            if (! $changedStage) {
                return;
            }

            // Names as they were for reading, ids for the pipeline report.
            CrmTimeline::record($deal, CrmTimeline::STAGE_CHANGED, $actor, [
                'entity' => 'deal',
                'title' => $deal->title,
                'from' => $from?->name,
                'to' => $stage->name,
                'from_stage_id' => $from?->id,
                'to_stage_id' => $stage->id,
            ]);

            if ($kind->value !== $wasStatus) {
                $type = match ($kind) {
                    StageKind::Won => CrmTimeline::DEAL_WON,
                    StageKind::Lost => CrmTimeline::DEAL_LOST,
                    StageKind::Open => CrmTimeline::DEAL_REOPENED,
                };

                CrmTimeline::record($deal, $type, $actor, array_filter([
                    'entity' => 'deal',
                    'title' => $deal->title,
                    'value_cents' => $deal->value_cents,
                    'reason' => $kind === StageKind::Lost ? $deal->lost_reason : null,
                ], fn ($value) => $value !== null));
            }

            if ($kind === StageKind::Won && $deal->customer) {
                CustomerLifecycle::promote($deal->customer, CustomerStatus::Customer, $actor);
            }
        });

        CrmNotifier::changed($deal);

        return $deal->fresh(['stage', 'pipeline', 'customer', 'owner', 'company']);
    }

    public function delete(Deal $deal, User $actor): void
    {
        DB::transaction(function () use ($deal, $actor) {
            if ($deal->customer) {
                CrmTimeline::record($deal->customer, CrmTimeline::DELETED, $actor, ['entity' => 'deal', 'title' => $deal->title]);
            }

            CrmActivity::query()->where('subject_type', 'deal')->where('subject_id', $deal->id)->delete();
            // Tasks about the deal stay with its customer.
            CrmTask::query()->where('subject_type', 'deal')->where('subject_id', $deal->id)
                ->update(['subject_type' => $deal->customer_id ? 'customer' : null, 'subject_id' => $deal->customer_id]);
            $deal->delete();
        });

        CrmNotifier::changed($deal);
    }

    /**
     * The customer a new deal (or lead) is about: the one named, the one
     * behind the conversation it was started from, or a new one typed in on
     * the spot.
     *
     * @param  array<string, mixed>  $data
     */
    public function customerFor(User $actor, array $data, ?\App\Models\Conversation $conversation): Customer
    {
        if (! empty($data['customer_id'])) {
            return CrmSubjects::resolve($actor, 'customer', (int) $data['customer_id'], 'customer_id');
        }

        if ($conversation) {
            return CrmSubjects::customerOfConversation($conversation);
        }

        if (! empty($data['customer']['name'])) {
            $fields = $this->customers->validate($actor->tenant_id, $data['customer'], creating: true);

            return $this->customers->create($actor, $fields);
        }

        throw ValidationException::withMessages(['customer_id' => __('crm.deals.customer_required')]);
    }

    private function stageOf(CrmPipeline $pipeline, int $stageId): CrmPipelineStage
    {
        $stage = $pipeline->stages()->find($stageId);

        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => __('crm.deals.stage_not_in_pipeline')]);
        }

        return $stage;
    }

    private function nextPosition(CrmPipelineStage $stage): int
    {
        return (int) Deal::query()->where('stage_id', $stage->id)->max('position') + 1;
    }

    /**
     * Put the deal at `$position` among its stage's deals and number the rest
     * around it — the order a person dragged them into on the board.
     */
    private function place(Deal $deal, CrmPipelineStage $stage, ?int $position): void
    {
        $others = Deal::query()
            ->where('stage_id', $stage->id)
            ->whereKeyNot($deal->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $index = $position === null ? count($others) : max(0, min($position, count($others)));
        array_splice($others, $index, 0, [$deal->id]);

        foreach ($others as $i => $id) {
            Deal::query()->whereKey($id)->where('position', '!=', $i)->update(['position' => $i]);
        }

        $deal->position = $index;
    }

    /** @return array<string, mixed> */
    private function snapshot(Deal $deal): array
    {
        return [
            'title' => $deal->title,
            'value_cents' => $deal->value_cents,
            'expected_close_date' => $deal->expected_close_date?->format('Y-m-d'),
            'owner_user_id' => $deal->owner_user_id,
            'company_id' => $deal->company_id,
        ];
    }

    /** @param  array<string, array<string, mixed>>  $changes */
    private function nameChanges(array &$changes): void
    {
        $names = [
            'owner_user_id' => fn ($id) => User::find($id)?->name,
            'company_id' => fn ($id) => Company::find($id)?->name,
        ];

        foreach ($names as $field => $name) {
            if (isset($changes[$field])) {
                $changes[$field]['from_name'] = $changes[$field]['from'] ? $name($changes[$field]['from']) : null;
                $changes[$field]['to_name'] = $changes[$field]['to'] ? $name($changes[$field]['to']) : null;
            }
        }
    }
}
