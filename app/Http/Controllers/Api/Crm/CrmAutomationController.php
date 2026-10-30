<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\AutomationRunStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrmAutomation;
use App\Models\CrmAutomationRun;
use App\Models\CrmPipeline;
use App\Models\Flow;
use App\Models\User;
use App\Services\Crm\Automation\AutomationDefinition;
use App\Services\Crm\CrmPipelines;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CRM › Automations: when something happens to a lead, deal, customer or
 * task, and it matches, do these things — and the history of every run.
 */
class CrmAutomationController extends Controller
{
    public function index(Request $request)
    {
        $automations = CrmAutomation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('creator:id,name')
            ->orderBy('name')
            ->get();

        $last = CrmAutomationRun::query()
            ->whereIn('automation_id', $automations->pluck('id'))
            ->selectRaw('automation_id, MAX(id) as id')
            ->groupBy('automation_id')
            ->pluck('id', 'automation_id');

        $lastStatus = CrmAutomationRun::query()->whereIn('id', $last->values())->pluck('status', 'automation_id');

        return response()->json([
            'data' => $automations->map(fn (CrmAutomation $automation) => $this->shape($automation) + [
                'last_status' => $lastStatus[$automation->id] ?? null,
            ])->values(),
        ]);
    }

    /**
     * The vocabulary, plus what an action can name in this workspace — here,
     * not read from the pages that own them, because whoever builds
     * automations may not be allowed to open those pages (flows, pipelines).
     */
    public function meta(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json(AutomationDefinition::meta() + [
            'people' => User::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'flows' => Flow::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'pipelines' => CrmPipelines::forTenant($tenantId)->map(fn (CrmPipeline $pipeline) => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'stages' => $pipeline->stages->map(fn ($stage) => ['id' => $stage->id, 'name' => $stage->name, 'kind' => $stage->kind->value])->values(),
            ])->values(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(['data' => $this->shape($this->find($request, $id))]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (CrmAutomation::query()->where('tenant_id', $user->tenant_id)->count() >= AutomationDefinition::MAX_AUTOMATIONS) {
            throw new HttpException(422, __('crm.automations.too_many', ['max' => AutomationDefinition::MAX_AUTOMATIONS]));
        }

        $meta = $this->validateMeta($request, true);
        $definition = AutomationDefinition::validate($user->tenant_id, $request->all());

        $automation = CrmAutomation::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'is_active' => (bool) ($meta['is_active'] ?? true),
            ...collect($meta)->only(['name', 'description'])->all(),
            ...$definition,
        ]);

        AuditLog::record('crm.automation_created', "Created CRM automation {$automation->name}", [
            'tenant_id' => $user->tenant_id,
            'automation_id' => $automation->id,
            'trigger' => $automation->trigger->value,
        ]);

        return response()->json(['data' => $this->shape($automation->load('creator:id,name'))], 201);
    }

    public function update(Request $request, string $id)
    {
        $automation = $this->find($request, $id);
        $meta = $this->validateMeta($request, false);
        $definition = $request->hasAny(['trigger', 'conditions', 'actions'])
            ? AutomationDefinition::validate($automation->tenant_id, [
                'trigger' => $request->input('trigger', $automation->trigger->value),
                'trigger_config' => $request->input('trigger_config', $automation->trigger_config),
                'match' => $request->input('match', $automation->match),
                'conditions' => $request->input('conditions', $automation->conditions ?? []),
                'actions' => $request->input('actions', $automation->actions),
            ])
            : [];

        $automation->fill([...collect($meta)->only(['name', 'description', 'is_active'])->all(), ...$definition]);

        // Switched back on: a clean slate, since whoever did it fixed what made it stop.
        if ($automation->isDirty('is_active') && $automation->is_active) {
            $automation->forceFill(['paused_reason' => null, 'consecutive_failures' => 0]);
        }

        $automation->save();

        AuditLog::record('crm.automation_updated', "Updated CRM automation {$automation->name}", [
            'tenant_id' => $automation->tenant_id,
            'automation_id' => $automation->id,
            'fields' => array_keys($request->only(['name', 'description', 'is_active', 'trigger', 'trigger_config', 'match', 'conditions', 'actions'])),
        ]);

        return response()->json(['data' => $this->shape($automation->load('creator:id,name'))]);
    }

    public function destroy(Request $request, string $id)
    {
        $automation = $this->find($request, $id);
        $automation->delete();

        AuditLog::record('crm.automation_deleted', "Deleted CRM automation {$automation->name}", [
            'tenant_id' => $automation->tenant_id,
            'automation_id' => $automation->id,
        ]);

        return response()->json(['message' => __('crm.automations.deleted')]);
    }

    /** The history: one automation's runs, or every automation's (`automation_id` absent). */
    public function runs(Request $request, ?string $id = null)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(AutomationRunStatus::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $request->user()->tenant_id;

        if ($id !== null) {
            $this->find($request, $id);
        }

        $runs = CrmAutomationRun::query()
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($q) => $q->where('automation_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with('automation:id,name')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return response()->json([
            'data' => collect($runs->items())->map(fn (CrmAutomationRun $run) => [
                'id' => $run->id,
                'automation' => $run->automation ? ['id' => $run->automation->id, 'name' => $run->automation->name] : null,
                'trigger' => $run->trigger,
                'subject' => ['type' => $run->subject_type, 'id' => $run->subject_id, 'title' => $run->subject_title],
                'customer_id' => $run->customer_id,
                'status' => $run->status->value,
                'results' => $run->results ?? [],
                'reason' => $run->error,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateMeta(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(CrmAutomation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger' => $automation->trigger->value,
            'trigger_config' => (object) ($automation->trigger_config ?? []),
            'match' => $automation->match,
            'conditions' => $automation->conditions ?? [],
            'actions' => $automation->actions ?? [],
            'is_active' => $automation->is_active,
            'paused_reason' => $automation->paused_reason,
            'runs_count' => (int) $automation->runs_count,
            'failures_count' => (int) $automation->failures_count,
            'last_run_at' => $automation->last_run_at?->toIso8601String(),
            'creator' => $automation->creator ? ['id' => $automation->creator->id, 'name' => $automation->creator->name] : null,
            'created_at' => $automation->created_at?->toIso8601String(),
            'updated_at' => $automation->updated_at?->toIso8601String(),
        ];
    }

    private function find(Request $request, string $id): CrmAutomation
    {
        return CrmAutomation::query()->where('tenant_id', $request->user()->tenant_id)->find($id)
            ?? throw new HttpException(404, __('crm.automations.not_found_automation'));
    }
}
