<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\StageKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\Deal;
use App\Services\Crm\CrmPipelines;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CRM › Pipelines: the boards deals move along and their stages. Reading is
 * open to whoever can see deals (the board draws them); changing them is
 * `pipelines.manage`.
 */
class PipelineController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $pipelines = CrmPipelines::forTenant($tenantId);

        $counts = Deal::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('stage_id, count(*) as aggregate')
            ->groupBy('stage_id')
            ->pluck('aggregate', 'stage_id');

        return response()->json([
            'data' => $pipelines->map(function (CrmPipeline $pipeline) use ($counts) {
                $shape = CrmPresenter::pipeline($pipeline);
                $shape['stages'] = collect($shape['stages'])
                    ->map(fn (array $stage) => $stage + ['deals_count' => (int) ($counts[$stage['id']] ?? 0)])
                    ->values();
                $shape['deals_count'] = (int) collect($shape['stages'])->sum('deals_count');

                return $shape;
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'stages' => ['nullable', 'array', 'max:'.CrmPipelines::MAX_STAGES],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'stages.*.kind' => ['nullable', Rule::in(StageKind::values())],
            'stages.*.color' => ['nullable', 'string', 'max:20'],
        ]);

        $pipeline = CrmPipelines::create($request->user()->tenant_id, $data['name'], $data['stages'] ?? []);

        return response()->json(['data' => CrmPresenter::pipeline($pipeline)], 201);
    }

    public function update(Request $request, string $id)
    {
        $pipeline = $this->find($request, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($pipeline, $data) {
            if (! empty($data['is_default'])) {
                CrmPipeline::query()->where('tenant_id', $pipeline->tenant_id)->update(['is_default' => false]);
                $pipeline->is_default = true;
            }

            if (isset($data['name'])) {
                $pipeline->name = $data['name'];
            }

            $pipeline->save();
        });

        return response()->json(['data' => CrmPresenter::pipeline($pipeline->load('stages'))]);
    }

    /**
     * Delete a pipeline. Its deals move to `move_to_pipeline_id` (each to the
     * stage with the same outcome — open deals to the first open stage);
     * without one, a pipeline that still holds deals is refused. The last
     * pipeline cannot go — deals need a board.
     */
    public function destroy(Request $request, string $id)
    {
        $pipeline = $this->find($request, $id);
        $tenantId = $pipeline->tenant_id;

        if (CrmPipeline::query()->where('tenant_id', $tenantId)->count() <= 1) {
            throw ValidationException::withMessages(['pipeline' => __('crm.pipelines.last_one')]);
        }

        $target = $request->filled('move_to_pipeline_id')
            ? CrmPipeline::query()->where('tenant_id', $tenantId)->with('stages')->find($request->integer('move_to_pipeline_id'))
            : null;

        if ($request->filled('move_to_pipeline_id') && (! $target || $target->id === $pipeline->id)) {
            throw ValidationException::withMessages(['move_to_pipeline_id' => __('crm.pipelines.move_to_invalid')]);
        }

        $count = Deal::query()->where('pipeline_id', $pipeline->id)->count();

        if ($count > 0 && ! $target) {
            throw ValidationException::withMessages(['pipeline' => __('crm.pipelines.pipeline_has_deals', ['count' => $count])]);
        }

        DB::transaction(function () use ($pipeline, $target, $tenantId) {
            if ($target) {
                foreach (StageKind::cases() as $kind) {
                    $stage = $target->stages->first(fn (CrmPipelineStage $s) => $s->kind === $kind);

                    Deal::query()->where('pipeline_id', $pipeline->id)->where('status', $kind->value)
                        ->update(['pipeline_id' => $target->id, 'stage_id' => $stage->id]);
                }
            }

            $wasDefault = $pipeline->is_default;
            $pipeline->stages()->delete();
            $pipeline->delete();

            if ($wasDefault) {
                CrmPipeline::query()->where('tenant_id', $tenantId)->orderBy('position')->limit(1)->update(['is_default' => true]);
            }
        });

        return response()->json(['message' => __('crm.pipelines.deleted')]);
    }

    public function storeStage(Request $request, string $id)
    {
        $pipeline = $this->find($request, $id);

        if ($pipeline->stages()->count() >= CrmPipelines::MAX_STAGES) {
            throw ValidationException::withMessages(['name' => __('crm.pipelines.too_many_stages', ['max' => CrmPipelines::MAX_STAGES])]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'kind' => ['nullable', Rule::in(StageKind::values())],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        // A new open stage goes after the last open one, before Won and Lost.
        $kind = $data['kind'] ?? StageKind::Open->value;
        $stages = $pipeline->stages()->get();
        $position = $kind === StageKind::Open->value
            ? (int) ($stages->filter(fn ($s) => $s->kind === StageKind::Open)->max('position') ?? -1) + 1
            : (int) ($stages->max('position') ?? -1) + 1;

        DB::transaction(function () use ($pipeline, $data, $kind, $position) {
            CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('position', '>=', $position)->increment('position');

            CrmPipelineStage::create([
                'tenant_id' => $pipeline->tenant_id,
                'pipeline_id' => $pipeline->id,
                'name' => $data['name'],
                'probability' => (int) ($data['probability'] ?? ($kind === StageKind::Won->value ? 100 : 0)),
                'kind' => $kind,
                'color' => $data['color'] ?? null,
                'position' => $position,
            ]);
        });

        return response()->json(['data' => CrmPresenter::pipeline($pipeline->load('stages'))], 201);
    }

    /**
     * Rename a stage or change its probability or color. Its outcome (`kind`)
     * may change only while it holds no deals — a deal's won or lost is
     * history, not something a settings screen rewrites.
     */
    public function updateStage(Request $request, string $id, string $stageId)
    {
        $pipeline = $this->find($request, $id);
        $stage = $pipeline->stages()->find($stageId) ?? throw new HttpException(404, __('crm.pipelines.stage_not_found'));

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'probability' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'kind' => ['sometimes', Rule::in(StageKind::values())],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        if (isset($data['kind']) && $data['kind'] !== $stage->kind->value && $stage->deals()->exists()) {
            throw ValidationException::withMessages(['kind' => __('crm.pipelines.stage_kind_locked')]);
        }

        DB::transaction(function () use ($stage, $data, $pipeline) {
            $stage->fill($data)->save();
            CrmPipelines::assertClosable($pipeline);
        });

        return response()->json(['data' => CrmPresenter::pipeline($pipeline->load('stages'))]);
    }

    public function destroyStage(Request $request, string $id, string $stageId)
    {
        $pipeline = $this->find($request, $id);
        $stage = $pipeline->stages()->find($stageId) ?? throw new HttpException(404, __('crm.pipelines.stage_not_found'));
        $moveTo = $request->filled('move_to') ? $pipeline->stages()->find($request->integer('move_to')) : null;

        if ($request->filled('move_to') && ! $moveTo) {
            throw ValidationException::withMessages(['move_to' => __('crm.pipelines.move_to_invalid')]);
        }

        CrmPipelines::deleteStage($stage, $moveTo);

        return response()->json(['data' => CrmPresenter::pipeline($pipeline->load('stages'))]);
    }

    /** The order of the stages, as the full list of their ids. */
    public function orderStages(Request $request, string $id)
    {
        $pipeline = $this->find($request, $id);
        $ids = $pipeline->stages()->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();

        $data = $request->validate([
            'stage_ids' => ['required', 'array'],
            'stage_ids.*' => ['integer'],
        ]);

        $given = collect($data['stage_ids'])->map(fn ($v) => (int) $v);

        if ($given->sort()->values()->all() !== $ids) {
            throw ValidationException::withMessages(['stage_ids' => __('crm.pipelines.order_incomplete')]);
        }

        DB::transaction(function () use ($given) {
            foreach ($given->values() as $position => $stageId) {
                CrmPipelineStage::query()->whereKey($stageId)->update(['position' => $position]);
            }
        });

        return response()->json(['data' => CrmPresenter::pipeline($pipeline->load('stages'))]);
    }

    private function find(Request $request, string $id): CrmPipeline
    {
        $pipeline = CrmPipeline::query()->where('tenant_id', $request->user()->tenant_id)->find($id);

        if (! $pipeline) {
            throw new HttpException(404, __('crm.pipelines.not_found'));
        }

        return $pipeline;
    }
}
