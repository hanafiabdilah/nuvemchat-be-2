<?php

namespace App\Http\Controllers\Api;

use App\Enums\Lead\LeadSource;
use App\Enums\Lead\Temperature;
use App\Events\LeadUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadPipeline;
use App\Models\LeadStage;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\PipelineProvisioner;
use App\Services\Lead\TemperatureScorer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The sales funnel.
 *
 * Everything is scoped on leads.tenant_id. Note this is a deliberately wider
 * scope than conversations, which are gated per connection — see the LeadUpdated
 * event for why, and for what it would take to narrow it later.
 */
class LeadController extends Controller
{
    public function __construct(
        private PipelineProvisioner $pipelines,
        private LeadResolver $resolver,
        private TemperatureScorer $scorer,
    ) {}

    /**
     * The board: columns, and the first page of cards in each.
     *
     * Deliberately not `index()` with a big page size. A six-column board that
     * fetches every lead to render is fine at forty cards and unusable at five
     * thousand — the same mistake as rendering the whole of IndexedDB. Each
     * column carries its own total so the header can say "23" while showing 20,
     * and the front end asks index() for the rest as the column is scrolled.
     */
    public function board(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $perColumn = min($request->integer('per_column', 20), 50);

        $pipeline = $request->filled('pipeline_id')
            ? $this->findPipeline($request, $request->integer('pipeline_id'))
            : $this->pipelines->ensureDefault($tenantId);

        $pipeline->load('stages');

        $closedSince = now()->subDays($request->integer('closed_days', 30));

        $columns = $pipeline->stages->map(function (LeadStage $stage) use ($request, $perColumn, $closedSince) {
            $query = $this->filtered($request)->where('stage_id', $stage->id);

            // Won and lost only accumulate, so an untrimmed board becomes a
            // graveyard where two useful columns sit beside four years of
            // history. Closed columns show a recent window; the full record is
            // still there under index() and on the contact's own page.
            if ($stage->kind->isTerminal()) {
                $query->where('closed_at', '>=', $closedSince);
            }

            return [
                'stage' => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'kind' => $stage->kind->value,
                    'position' => $stage->position,
                ],
                'total' => (clone $query)->count(),
                'value_sum' => (float) (clone $query)->sum('value'),
                'leads' => LeadResource::collection(
                    $query->with(['contact', 'owner'])
                        ->orderByDesc('stage_changed_at')
                        ->orderByDesc('id')
                        ->limit($perColumn)
                        ->get()
                ),
            ];
        });

        return response()->json([
            'pipeline' => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
            ],
            'columns' => $columns,
        ]);
    }

    /** Flat, paginated list — the column "load more" and the list view. */
    public function index(Request $request)
    {
        $leads = $this->filtered($request)
            ->with(['contact', 'owner'])
            ->when($request->filled('stage_id'), fn ($query) => $query->where('stage_id', $request->integer('stage_id')))
            ->orderByDesc('stage_changed_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 30));

        return LeadResource::collection($leads);
    }

    public function show(Request $request, int $id)
    {
        $lead = $this->findForTenant($request, $id)
            ->load(['contact', 'owner', 'conversations', 'stageEvents.user']);

        return new LeadResource($lead);
    }

    /**
     * Open a card by hand — the phone call, the trade show, the referral.
     *
     * The invariant does the arguing: if this contact already has an open lead,
     * creating a second one is refused with the reason, rather than being
     * allowed to produce two cards that later fight over the same messages.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'owner_id' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'integer'],
        ]);

        $contact = Contact::where('id', $data['contact_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        if ($contact->is_group) {
            throw ValidationException::withMessages([
                'contact_id' => 'Um grupo não pode virar lead.',
            ]);
        }

        if ($existing = $this->resolver->openLeadFor($contact)) {
            throw ValidationException::withMessages([
                'contact_id' => "Este contato já tem um lead aberto (#{$existing->id}).",
            ]);
        }

        $lead = $this->resolver->open($contact, null, LeadSource::Manual);

        $lead->update(array_filter([
            'title' => $data['title'] ?? null,
            'value' => $data['value'] ?? null,
            'owner_id' => $this->resolveOwnerId($request, $data['owner_id'] ?? null),
        ], fn ($value) => $value !== null));

        $this->scorer->apply($lead);
        broadcast(new LeadUpdated($lead));

        return (new LeadResource($lead->load(['contact', 'owner'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Everything about a card except which column it is in. */
    public function update(Request $request, int $id)
    {
        $lead = $this->findForTenant($request, $id);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'owner_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('owner_id', $data)) {
            $data['owner_id'] = $this->resolveOwnerId($request, $data['owner_id']);
        }

        $lead->update($data);

        broadcast(new LeadUpdated($lead));

        return new LeadResource($lead->load(['contact', 'owner']));
    }

    /**
     * The drag.
     *
     * Its own endpoint rather than a field on update() because moving a card is
     * not a field edit: it writes the audit row the conversion report is built
     * from, stamps stage_changed_at, and derives won/lost from the target
     * column. Routing it through update() would eventually let some caller move
     * a card without leaving a trace, and the trace is the point.
     */
    public function move(Request $request, int $id)
    {
        $lead = $this->findForTenant($request, $id);

        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $stage = LeadStage::whereKey($data['stage_id'])
            ->whereHas('pipeline', fn ($query) => $query->where('tenant_id', $request->user()->tenant_id))
            ->firstOrFail();

        if ($lead->stage_id === $stage->id) {
            return new LeadResource($lead->load(['contact', 'owner']));
        }

        $lead->moveToStage($stage, $request->user(), $data['lost_reason'] ?? null);

        // Moving a card is itself a signal of life — rescore so the temperature
        // reflects it immediately rather than at the top of the next hour.
        $this->scorer->apply($lead);

        broadcast(new LeadUpdated($lead, moved: true));

        return new LeadResource($lead->load(['contact', 'owner']));
    }

    public function destroy(Request $request, int $id)
    {
        $lead = $this->findForTenant($request, $id);

        // Conversations keep their history; only the card goes.
        $lead->conversations()->update(['lead_id' => null]);
        $lead->delete();

        return response()->json(['message' => 'Lead removido.']);
    }

    /**
     * Who a card can be handed to.
     *
     * Its own endpoint under leads.view rather than reusing /agents, which
     * needs agents.view — being able to say "this one is mine" should not
     * require permission to manage the team.
     */
    public function owners(Request $request)
    {
        $owners = \App\Models\User::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $owners]);
    }

    /** Every lead this person has ever been, newest first. */
    public function forContact(Request $request, int $contactId)
    {
        Contact::where('id', $contactId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $leads = Lead::where('contact_id', $contactId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['contact', 'owner'])
            ->orderByDesc('id')
            ->get();

        return LeadResource::collection($leads);
    }

    /**
     * The filter set every board and list query starts from.
     *
     * Tenant scoping lives here and nowhere else, for the same reason
     * StatsScope centralises it: it is the one thing that must never be
     * forgotten, and a query that builds its own joins will eventually forget.
     */
    private function filtered(Request $request)
    {
        $query = Lead::where('tenant_id', $request->user()->tenant_id);

        // No default status filter: the board's own won and lost columns hold
        // closed leads, and filtering them out here would render those two
        // columns permanently empty.
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $query->when($request->filled('owner_id'), function ($q) use ($request) {
            $owner = $request->string('owner_id')->toString();

            // "unassigned" is a real filter, and the one a manager reaches for
            // first: which of these has nobody picked up?
            return $owner === 'none'
                ? $q->whereNull('owner_id')
                : $q->where('owner_id', (int) $owner);
        });

        $query->when($request->filled('temperature'), function ($q) use ($request) {
            $bands = array_filter(
                (array) $request->input('temperature'),
                fn ($band) => Temperature::tryFrom($band) !== null
            );

            return $bands ? $q->whereIn('temperature', $bands) : $q;
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $term = '%'.$request->string('search').'%';

            return $q->where(fn ($inner) => $inner
                ->where('title', 'like', $term)
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $term)->orWhere('external_id', 'like', $term))
            );
        });

        return $query;
    }

    private function findForTenant(Request $request, int $id): Lead
    {
        return Lead::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();
    }

    private function findPipeline(Request $request, int $id): LeadPipeline
    {
        return LeadPipeline::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();
    }

    /** Only someone in this workspace may own a card. */
    private function resolveOwnerId(Request $request, ?int $ownerId): ?int
    {
        if ($ownerId === null) {
            return null;
        }

        return \App\Models\User::where('id', $ownerId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail()
            ->id;
    }
}
