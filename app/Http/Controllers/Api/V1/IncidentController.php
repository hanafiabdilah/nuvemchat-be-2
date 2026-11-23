<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Incident\IncidentSource;
use App\Enums\Incident\ResolutionAudience;
use App\Http\Controllers\Controller;
use App\Http\Resources\Incident\IncidentResource;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Models\Incident;
use App\Services\Incident\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/incidents — switching an incident on and off from another system.
 *
 * The case this exists for: a monitoring tool that notices the outage before
 * anybody opens the dashboard. Before incidents, that tool answered the
 * customers itself through /v1/send-message — once per message, with the flow
 * answering on top. Now it opens the incident and Pingly does the rest: once
 * per conversation, the keywords, the pacing, the "it is fixed" message.
 *
 * Connections are named by their public id (`conn_…`), never the internal one.
 */
class IncidentController extends Controller
{
    public function __construct(private IncidentService $incidents) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'all'])]]);

        $incidents = Incident::with('connections:id,public_id')
            ->where('tenant_id', $this->key($request)->tenant_id)
            ->when(($data['status'] ?? 'open') === 'open', fn ($query) => $query->open())
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $incidents->map(fn (Incident $incident) => IncidentResource::forApi($incident)->resolve($request))->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => IncidentResource::forApi($this->find($request, $id))->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = $this->key($request);
        $input = $this->withInternalConnectionIds($request, $key);

        $data = $this->incidents->validate($input);
        $incident = $this->incidents->create($key->tenant, $data, null, IncidentSource::Api, $key);

        return response()->json(['data' => IncidentResource::forApi($incident)->resolve($request)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $key = $this->key($request);
        $input = $this->withInternalConnectionIds($request, $key);

        $data = $this->incidents->validate($input, partial: true);
        $incident = $this->incidents->update($this->find($request, $id), $data, null);

        return response()->json(['data' => IncidentResource::forApi($incident)->resolve($request)]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'send_resolution' => ['sometimes', 'boolean'],
            'resolution_message' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'resolution_audience' => ['sometimes', Rule::enum(ResolutionAudience::class)],
        ]);

        $incident = $this->incidents->resolve(
            $this->find($request, $id),
            null,
            (bool) ($data['send_resolution'] ?? false),
            $data['resolution_message'] ?? null,
            isset($data['resolution_audience']) ? ResolutionAudience::from($data['resolution_audience']) : null,
        );

        return response()->json(['data' => IncidentResource::forApi($incident)->resolve($request)]);
    }

    /**
     * The request with `connection_ids` turned from public ids into ours,
     * scoped to the key's workspace — one from another workspace reads exactly
     * like one that does not exist.
     *
     * @return array<string, mixed>
     */
    private function withInternalConnectionIds(Request $request, ApiKey $key): array
    {
        $input = $request->all();

        if (! array_key_exists('connection_ids', $input)) {
            return $input;
        }

        if (! is_array($input['connection_ids'])) {
            throw ValidationException::withMessages(['connection_ids' => 'connection_ids must be a list of connection ids (conn_…).']);
        }

        $publicIds = array_values(array_unique(array_filter($input['connection_ids'], 'is_string')));

        $ids = Connection::where('tenant_id', $key->tenant_id)
            ->whereIn('public_id', $publicIds)
            ->pluck('id', 'public_id');

        $missing = array_values(array_diff($publicIds, $ids->keys()->all()));

        if ($missing !== [] || count($publicIds) !== count($input['connection_ids'])) {
            throw ValidationException::withMessages([
                'connection_ids' => 'Unknown connection id: '.implode(', ', $missing ?: ['(not a string)']).'. List them with GET /api/v1/connections.',
            ]);
        }

        $input['connection_ids'] = $ids->values()->all();

        return $input;
    }

    private function find(Request $request, int $id): Incident
    {
        return Incident::with('connections:id,public_id')
            ->where('tenant_id', $this->key($request)->tenant_id)
            ->findOrFail($id);
    }

    private function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }
}
