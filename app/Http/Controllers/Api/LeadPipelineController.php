<?php

namespace App\Http\Controllers\Api;

use App\Enums\Lead\StageKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadPipelineResource;
use App\Models\LeadPipeline;
use App\Models\LeadStage;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The shape of the funnel — how many columns there are and what counts as a
 * sale. A different decision from working a card, hence its own permission.
 */
class LeadPipelineController extends Controller
{
    public function __construct(
        private PipelineProvisioner $pipelines,
    ) {}

    /** Always returns at least one funnel: the tenant's is created on first ask. */
    public function index(Request $request)
    {
        $this->pipelines->ensureDefault($request->user()->tenant_id);

        $pipelines = LeadPipeline::where('tenant_id', $request->user()->tenant_id)
            ->with('stages')
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->get();

        return LeadPipelineResource::collection($pipelines);
    }

    public function storeStage(Request $request, int $pipelineId)
    {
        $pipeline = $this->findForTenant($request, $pipelineId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:32'],
            'kind' => ['nullable', Rule::enum(StageKind::class)],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = $pipeline->stages()->create([
            'name' => $data['name'],
            'color' => $data['color'] ?? 'slate',
            'kind' => $data['kind'] ?? StageKind::Open,
            'position' => $data['position'] ?? ((int) $pipeline->stages()->max('position') + 1),
        ]);

        return response()->json(['data' => $stage], 201);
    }

    public function updateStage(Request $request, int $pipelineId, int $stageId)
    {
        $pipeline = $this->findForTenant($request, $pipelineId);
        $stage = $pipeline->stages()->whereKey($stageId)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', 'string', 'max:32'],
            'kind' => ['sometimes', Rule::enum(StageKind::class)],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        // Changing what a column *means* rewrites history: every lead sitting
        // in it would silently become a sale, or stop being one, and the
        // reports would disagree with themselves across the change. Renaming is
        // always fine — that is the point of keying on kind rather than name.
        if (isset($data['kind']) && $data['kind'] !== $stage->kind->value && $stage->leads()->exists()) {
            throw ValidationException::withMessages([
                'kind' => 'Mova os leads desta etapa antes de mudar o tipo dela.',
            ]);
        }

        $stage->update($data);

        return response()->json(['data' => $stage->fresh()]);
    }

    /**
     * Deleting a column has to say where its cards go, because the FK is
     * restrictOnDelete and would otherwise fail with a database error the agent
     * cannot act on.
     */
    public function destroyStage(Request $request, int $pipelineId, int $stageId)
    {
        $pipeline = $this->findForTenant($request, $pipelineId);
        $stage = $pipeline->stages()->whereKey($stageId)->firstOrFail();

        if ($pipeline->stages()->where('kind', StageKind::Open)->count() <= 1 && $stage->kind === StageKind::Open) {
            throw ValidationException::withMessages([
                'stage' => 'O funil precisa de pelo menos uma etapa aberta.',
            ]);
        }

        if ($stage->leads()->exists()) {
            $targetId = $request->integer('move_to_stage_id');

            $target = $targetId
                ? $pipeline->stages()->whereKey($targetId)->whereKeyNot($stage->id)->first()
                : null;

            if (! $target) {
                throw ValidationException::withMessages([
                    'move_to_stage_id' => 'Escolha para qual etapa os leads devem ir.',
                ]);
            }

            $this->moveLeads($stage, $target, $request);
        }

        $stage->delete();

        return response()->json(['message' => 'Etapa removida.']);
    }

    private function moveLeads(LeadStage $from, LeadStage $to, Request $request): void
    {
        $from->leads()->with([])->chunkById(200, function ($leads) use ($to, $request) {
            foreach ($leads as $lead) {
                // Through moveToStage so the audit log records the reshuffle
                // too — otherwise a stage deletion would teleport cards and the
                // conversion report would show them leaving nowhere.
                $lead->moveToStage($to, $request->user());
            }
        });
    }

    private function findForTenant(Request $request, int $id): LeadPipeline
    {
        return LeadPipeline::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('stages')
            ->firstOrFail();
    }
}
