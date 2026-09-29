<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\AiAgentHub\AiToolCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/conversations/tools — the AI Hub running one of our tools in
 * the middle of its own run ("Agente IA com ações").
 *
 * Not a general-purpose surface: the conversation is named by `callback_ref`,
 * the signed handle minted inside each run, so the only caller that can use
 * this is one that received a run. Everything the endpoint decides lives in
 * AiToolCallService; this only shapes the request.
 */
class ConversationToolController extends Controller
{
    public function __construct(
        private AiToolCallService $tools,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'callback_ref' => ['required', 'string', 'max:512'],
            'tool' => ['required', 'string', 'max:64', 'regex:/^[a-z_]+$/'],
            'arguments' => ['sometimes', 'nullable', 'array'],
            'run_id' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        // Required, because this is exactly where a retry after a timeout
        // becomes a second charge. The hub is asked for {runId}:{toolCallId}.
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Envie um cabeçalho Idempotency-Key (até 191 caracteres), único por chamada de ferramenta e igual nas retentativas.',
            ]);
        }

        // The hub calls with the workspace key it holds for this agent; the
        // reference decides which conversation is touched.
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $result = $this->tools->call($key->tenant, $key, $idempotencyKey, $data);

        return response()->json($result['body'], $result['status']);
    }
}
