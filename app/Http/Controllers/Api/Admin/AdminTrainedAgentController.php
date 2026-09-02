<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainedAgent\TrainedAgentBlueprintResource;
use App\Http\Resources\TrainedAgent\TrainedAgentCategoryResource;
use App\Models\AuditLog;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentCategory;
use App\Models\TrainedAgentHire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The platform's trained-agent catalog: segments, the agents sold inside them,
 * and a read-only view of who took what.
 *
 * Everything here is content the platform authors once and every tenant buys a
 * copy of, so it is deliberately a plain CRUD surface — the interesting
 * machinery (allowance, payment, forking) lives on the tenant side.
 */
class AdminTrainedAgentController extends Controller
{
    /* ------------------------------------------------------------------
     | Categories
     * ------------------------------------------------------------------ */

    public function categories()
    {
        $categories = TrainedAgentCategory::query()
            ->withCount('blueprints')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return TrainedAgentCategoryResource::collection($categories);
    }

    public function storeCategory(Request $request)
    {
        $validated = $this->validateCategory($request);
        $validated['slug'] ??= Str::slug($validated['name']);

        $category = TrainedAgentCategory::create($validated);

        AuditLog::record('trained-agents.category.create', "Created category {$category->name}", ['id' => $category->id]);

        return (new TrainedAgentCategoryResource($category))->response()->setStatusCode(201);
    }

    public function updateCategory(Request $request, TrainedAgentCategory $category)
    {
        $category->update($this->validateCategory($request, $category));

        AuditLog::record('trained-agents.category.update', "Updated category {$category->name}", ['id' => $category->id]);

        return new TrainedAgentCategoryResource($category->fresh());
    }

    /**
     * Categories are hard-deleted; blueprints inside them survive with a null
     * category (the migration's nullOnDelete). Retiring a segment must never
     * silently take the agents people already bought out of the catalog.
     */
    /**
     * Persist a new order for the catalog, or for the segments.
     *
     * Takes ids in the order they should appear — what a drag produces. The
     * blueprint and category forms no longer ask for a position at all: nobody
     * should be computing integers to move a card one place to the left.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'type' => ['required', 'string', 'in:blueprints,categories'],
        ]);

        $model = $validated['type'] === 'categories'
            ? TrainedAgentCategory::class
            : TrainedAgentBlueprint::class;

        foreach ($validated['ids'] as $position => $id) {
            $model::whereKey($id)->update(['sort_order' => $position]);
        }

        return response()->json(['message' => 'Order saved']);
    }

    public function destroyCategory(TrainedAgentCategory $category)
    {
        $name = $category->name;
        $category->delete();

        AuditLog::record('trained-agents.category.delete', "Deleted category {$name}");

        return response()->json(['message' => 'Category deleted']);
    }

    /* ------------------------------------------------------------------
     | Blueprints
     * ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $blueprints = TrainedAgentBlueprint::query()
            ->with('category')
            ->withCount('hires')
            ->when($request->filled('category_id'), fn ($q) => $q->where('trained_agent_category_id', $request->integer('category_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return TrainedAgentBlueprintResource::collection($blueprints);
    }

    public function show(TrainedAgentBlueprint $blueprint)
    {
        return new TrainedAgentBlueprintResource($blueprint->load('category')->loadCount('hires'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateBlueprint($request);
        $validated['slug'] ??= Str::slug($validated['name']);

        $blueprint = TrainedAgentBlueprint::create($validated);

        AuditLog::record('trained-agents.blueprint.create', "Created trained agent {$blueprint->name}", [
            'id' => $blueprint->id,
            'price_cents' => $blueprint->price_cents,
        ]);

        return (new TrainedAgentBlueprintResource($blueprint->load('category')))->response()->setStatusCode(201);
    }

    public function update(Request $request, TrainedAgentBlueprint $blueprint)
    {
        $blueprint->update($this->validateBlueprint($request, $blueprint));

        AuditLog::record('trained-agents.blueprint.update', "Updated trained agent {$blueprint->name}", [
            'id' => $blueprint->id,
            'price_cents' => $blueprint->price_cents,
        ]);

        return new TrainedAgentBlueprintResource($blueprint->fresh()->load('category'));
    }

    /**
     * Soft delete. Hires point back at this row and a receipt has to stay
     * readable, so the record survives; `available()` already hides it from
     * every catalog.
     */
    public function destroy(TrainedAgentBlueprint $blueprint)
    {
        $name = $blueprint->name;
        $blueprint->delete();

        AuditLog::record('trained-agents.blueprint.delete', "Deleted trained agent {$name}", ['id' => $blueprint->id]);

        return response()->json(['message' => 'Trained agent deleted']);
    }

    /**
     * Duplicate a blueprint. The catalog grows by variation — a dentist's
     * agent is a medical one with a different vocabulary — and retyping a
     * thousand-word prompt to change ten of its words is how catalogs stop
     * growing.
     */
    public function duplicate(TrainedAgentBlueprint $blueprint)
    {
        $copy = $blueprint->replicate(['deleted_at']);
        $copy->name = $blueprint->name.' (copy)';
        $copy->slug = Str::slug($copy->name).'-'.Str::lower(Str::random(4));
        // A copy is a draft until someone says otherwise: publishing an
        // unedited duplicate into the live catalog is never what was meant.
        $copy->is_public = false;
        $copy->save();

        AuditLog::record('trained-agents.blueprint.duplicate', "Duplicated trained agent {$blueprint->name}", [
            'from' => $blueprint->id,
            'to' => $copy->id,
        ]);

        return (new TrainedAgentBlueprintResource($copy->load('category')))->response()->setStatusCode(201);
    }

    /* ------------------------------------------------------------------
     | Hires (read-only)
     * ------------------------------------------------------------------ */

    /**
     * Who took what. `?attention=1` narrows it to purchases the platform owes
     * somebody something for — paid, never delivered, not yet refunded. Without
     * a way to list those, the flag written on failure would be another one
     * nobody ever reads.
     */
    public function hires(Request $request)
    {
        $hires = TrainedAgentHire::query()
            ->with(['tenant:id,name', 'blueprint:id,name'])
            ->when($request->boolean('attention'), fn ($q) => $q->needsAttention())
            ->when($request->filled('blueprint_id'), fn ($q) => $q->where('trained_agent_blueprint_id', $request->integer('blueprint_id')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => $hires->getCollection()->map(fn (TrainedAgentHire $hire) => [
                'id' => $hire->id,
                'tenant_id' => $hire->tenant_id,
                'tenant_name' => $hire->tenant?->name,
                'blueprint_id' => $hire->trained_agent_blueprint_id,
                'blueprint_name' => $hire->blueprint?->name ?? ($hire->blueprint_snapshot['name'] ?? null),
                'agent_name' => $hire->agent_name,
                'source' => $hire->source->value,
                'status' => $hire->status->value,
                'price_cents' => $hire->price_cents,
                'currency' => $hire->currency,
                'needs_refund' => $hire->needsAttention(),
                'failure_reason' => $hire->meta['failure']['reason'] ?? null,
                'hired_at' => $hire->hired_at?->toISOString(),
                'created_at' => $hire->created_at?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $hires->currentPage(),
                'last_page' => $hires->lastPage(),
                'total' => $hires->total(),
            ],
        ]);
    }

    /**
     * Mark a failed purchase as settled once the refund has actually been
     * made. Same contract as the API Way button: it records that a human dealt
     * with it, so the attention list can drain.
     */
    public function settleRefund(TrainedAgentHire $hire)
    {
        $meta = $hire->meta ?? [];
        $meta['refund_settled_at'] = now()->toISOString();
        $hire->update(['meta' => $meta]);

        AuditLog::record('trained-agents.hire.settle-refund', "Settled refund for hire #{$hire->id}", [
            'hire_id' => $hire->id,
            'tenant_id' => $hire->tenant_id,
            'price_cents' => $hire->price_cents,
        ]);

        return response()->json(['message' => 'Refund marked as settled']);
    }

    /* ------------------------------------------------------------------
     | Validation
     * ------------------------------------------------------------------ */

    private function validateCategory(Request $request, ?TrainedAgentCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('trained_agent_categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);
    }

    private function validateBlueprint(Request $request, ?TrainedAgentBlueprint $blueprint = null): array
    {
        return $request->validate([
            'trained_agent_category_id' => ['nullable', 'integer', 'exists:trained_agent_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', Rule::unique('trained_agent_blueprints', 'slug')->ignore($blueprint?->id)],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:60'],

            'model' => ['required', 'string', 'max:100'],
            'system_prompt' => ['required', 'string'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
            'handoff_rules' => ['nullable', 'array'],

            'profile' => ['nullable', 'array'],
            'profile.language' => ['nullable', 'string', 'max:20'],
            'profile.tone' => ['nullable', 'string', 'max:60'],
            'profile.response_style' => ['nullable', 'string', 'max:60'],
            'profile.instructions' => ['nullable', 'array'],
            'profile.instructions.*' => ['string'],
            'profile.limits' => ['nullable', 'array'],

            'knowledge' => ['nullable', 'array'],
            'knowledge.*.title' => ['required', 'string', 'max:255'],
            'knowledge.*.content' => ['required', 'string'],
            'knowledge.*.tags' => ['nullable', 'array'],
            'knowledge.*.tags.*' => ['string', 'max:60'],

            'skills' => ['nullable', 'array'],
            'skills.*.name' => ['required', 'string', 'max:150'],
            'skills.*.description' => ['nullable', 'string'],
            'skills.*.instructions' => ['nullable', 'array'],
            'skills.*.instructions.*' => ['string'],

            'training_examples' => ['nullable', 'array'],
            'training_examples.*.type' => ['nullable', 'string', 'max:60'],
            'training_examples.*.input' => ['required', 'string'],
            'training_examples.*.expected_output' => ['required', 'string'],
            'training_examples.*.notes' => ['nullable', 'string'],

            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
