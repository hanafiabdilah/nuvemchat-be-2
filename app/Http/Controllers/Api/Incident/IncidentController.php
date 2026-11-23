<?php

namespace App\Http\Controllers\Api\Incident;

use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliveryStatus;
use App\Enums\Incident\IncidentStatus;
use App\Enums\Incident\MatchMode;
use App\Enums\Incident\ResolutionAudience;
use App\Enums\Message\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Incident\IncidentDeliveryResource;
use App\Http\Resources\Incident\IncidentResource;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\User;
use App\Services\Incident\IncidentMatcher;
use App\Services\Incident\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Incidents, from the dashboard (Incidents page + the banner over the inbox).
 *
 * Everything that changes what customers are told goes through
 * IncidentService, the same one the public API calls; this controller only
 * reads the request and decides who may see what.
 */
class IncidentController extends Controller
{
    public function __construct(private IncidentService $incidents) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'ended'])],
        ]);

        $query = Incident::with(['connections:id,name,channel', 'creator:id,name', 'resolver:id,name', 'aiAgent:id,name'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('id');

        if (($data['status'] ?? null) === 'open') {
            $query->open();
        } elseif (($data['status'] ?? null) === 'ended') {
            $query->whereIn('status', [IncidentStatus::Resolved, IncidentStatus::Expired, IncidentStatus::Canceled]);
        }

        return IncidentResource::collection($query->paginate(25));
    }

    /**
     * What the banner over the inbox shows: the incidents answering right now
     * on the connections this person can see. No permission — knowing why the
     * inbox is answering itself is not a privilege — and only the incidents
     * on their own inboxes.
     */
    public function active(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accessible = $user->canAccessAllConnections() ? null : $user->accessibleConnectionIds();

        $incidents = Incident::with('connections:id,name,channel')
            ->where('tenant_id', $user->tenant_id)
            ->where(fn ($query) => $query->live()->orWhere('status', IncidentStatus::Scheduled))
            ->orderBy('ends_at')
            ->get()
            ->map(function (Incident $incident) use ($accessible) {
                $connections = $incident->connections
                    ->filter(fn ($c) => $accessible === null || in_array($c->id, $accessible, true))
                    ->values();

                if ($connections->isEmpty()) {
                    return null;
                }

                $stats = $this->incidents->stats($incident);

                return [
                    'id' => $incident->id,
                    'title' => $incident->title,
                    'status' => $incident->status->value,
                    'live' => $incident->isLive(),
                    'message' => $incident->message,
                    'media_url' => $incident->media_url,
                    'media_type' => $incident->media_type,
                    'match_mode' => $incident->match_mode->value,
                    'reply_mode' => $incident->reply_mode->value,
                    'starts_at' => $incident->starts_at?->toIso8601String(),
                    'ends_at' => $incident->ends_at?->toIso8601String(),
                    'connection_ids' => $connections->pluck('id')->values(),
                    'connections' => $connections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'channel' => $c->channel->value])->values(),
                    'notified' => $stats['notice'][DeliveryStatus::Sent->value] ?? 0,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $incidents]);
    }

    public function show(Request $request, int $id): IncidentResource
    {
        return new IncidentResource($this->find($request, $id)->load(['connections:id,name,channel', 'creator:id,name', 'resolver:id,name', 'aiAgent:id,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->incidents->validate($request->all());

        $incident = $this->incidents->create($request->user()->tenant, $data, $request->user());

        return (new IncidentResource($incident->load(['creator:id,name'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): IncidentResource
    {
        $data = $this->incidents->validate($request->all(), partial: true);

        return new IncidentResource($this->incidents->update($this->find($request, $id), $data, $request->user()));
    }

    public function extend(Request $request, int $id): IncidentResource
    {
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:'.Incident::MIN_DURATION_MINUTES, 'max:'.Incident::MAX_DURATION_MINUTES],
        ]);

        return new IncidentResource($this->incidents->extend($this->find($request, $id), (int) $data['minutes']));
    }

    public function resolve(Request $request, int $id): IncidentResource
    {
        $data = $request->validate([
            'send_resolution' => ['sometimes', 'boolean'],
            'resolution_message' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'resolution_audience' => ['sometimes', Rule::enum(ResolutionAudience::class)],
        ]);

        $incident = $this->incidents->resolve(
            $this->find($request, $id),
            $request->user(),
            (bool) ($data['send_resolution'] ?? false),
            $data['resolution_message'] ?? null,
            isset($data['resolution_audience']) ? ResolutionAudience::from($data['resolution_audience']) : null,
        );

        return new IncidentResource($incident);
    }

    public function cancel(Request $request, int $id): IncidentResource
    {
        return new IncidentResource($this->incidents->cancel($this->find($request, $id)));
    }

    /**
     * Who was told, who was not and why — only for the conversations this
     * person may read, since each row shows what the customer wrote.
     */
    public function deliveries(Request $request, int $id)
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::enum(DeliveryKind::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(DeliveryStatus::class)],
        ]);

        $incident = $this->find($request, $id);
        $user = $request->user();

        $deliveries = IncidentDelivery::with(['conversation.contact', 'triggerMessage'])
            ->where('incident_id', $incident->id)
            ->where('kind', $data['kind'] ?? DeliveryKind::Notice->value)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->whereHas('conversation', fn ($query) => $query->visibleTo($user))
            ->latest('id')
            ->paginate(50);

        return IncidentDeliveryResource::collection($deliveries);
    }

    public function sendDelivery(Request $request, int $id, int $deliveryId): IncidentDeliveryResource
    {
        $incident = $this->find($request, $id);
        $user = $request->user();

        $delivery = IncidentDelivery::where('incident_id', $incident->id)
            ->whereHas('conversation', fn ($query) => $query->visibleTo($user))
            ->findOrFail($deliveryId);

        $delivery = $this->incidents->sendManually($incident, $delivery);

        return new IncidentDeliveryResource($delivery->load(['conversation.contact', 'triggerMessage']));
    }

    /**
     * The "Test a message" box: what the incident would decide about a
     * message, read by the same matcher that will decide it for real — so the
     * form never promises a match the server would not make.
     */
    public function testKeywords(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:4096'],
            'match_mode' => ['required', Rule::enum(MatchMode::class)],
            'keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
            'exclude_keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'exclude_keywords.*' => ['string', 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
        ]);

        $verdict = IncidentMatcher::judge(
            MatchMode::from($data['match_mode']),
            IncidentMatcher::cleanKeywords($data['keywords'] ?? []),
            IncidentMatcher::cleanKeywords($data['exclude_keywords'] ?? []),
            MessageType::Text,
            $data['text'] ?? '',
        );

        return response()->json(['data' => $verdict->toArray()]);
    }

    private function find(Request $request, int $id): Incident
    {
        return Incident::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
