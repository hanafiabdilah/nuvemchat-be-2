<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Lead\LeadCloseService;
use App\Services\Lead\LeadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/leads — another system hands Pingly a prospect.
 *
 * Authenticated by the workspace's API key (V1\ApiKeyAuth). The work is in
 * LeadIntakeService; this only checks the shape of the request.
 */
class LeadController extends Controller
{
    private const METADATA_MAX_KEY = 40;

    private const METADATA_MAX_VALUE = 500;

    public function __construct(
        private LeadIntakeService $intake,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'connection_id' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:191'],
            'title' => ['nullable', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'stage_id' => ['nullable', 'integer'],
            'assign_to' => ['nullable'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'message' => ['nullable', 'string', 'max:4096'],
            'template' => ['nullable', 'array'],
            'template.name' => ['required_with:template', 'string', 'max:512'],
            'template.language' => ['required_with:template', 'string', 'max:15'],
            'template.components' => ['nullable', 'array'],
        ]);

        $this->assertMetadata($data['metadata'] ?? []);

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $outcome = $this->intake->receive($key->tenant, $key, $data);

        return response()->json(['data' => $outcome['body']], $outcome['status']);
    }

    /** POST /api/v1/leads/close — won or lost, by the reference the lead was sent with. */
    public function close(Request $request, LeadCloseService $closer): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:191'],
            'status' => ['required', 'in:won,lost'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        ['lead' => $lead, 'changed' => $changed, 'reference' => $reference] = $closer->close($key->tenant, $data);

        return response()->json(['data' => [
            'id' => $lead->id,
            'reference' => $reference,
            'status' => $lead->status->value,
            'value' => $lead->value !== null ? (float) $lead->value : null,
            'currency' => $lead->currency,
            'lost_reason' => $lead->lost_reason,
            'stage' => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name] : null,
            'closed_at' => $lead->closed_at?->utc()->toIso8601ZuluString(),
            'changed' => $changed,
        ]]);
    }

    /**
     * Flat key → simple value only: it is printed into a note in the thread,
     * and a nested object there reads as noise.
     */
    private function assertMetadata(array $metadata): void
    {
        foreach ($metadata as $field => $value) {
            $valid = is_string($field)
                && mb_strlen($field) <= self::METADATA_MAX_KEY
                && ($value === null || is_scalar($value))
                && (! is_string($value) || mb_strlen($value) <= self::METADATA_MAX_VALUE);

            if (! $valid) {
                throw ValidationException::withMessages([
                    'metadata' => 'Use até 20 pares chave → valor, com chaves de até 40 caracteres e valores simples (texto, número ou booleano) de até 500 caracteres.',
                ]);
            }
        }
    }
}
