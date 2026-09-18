<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\AiAgentHub\AiProactiveMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/conversations/messages — the AI Hub writing into a conversation
 * it is currently serving, without the customer having written first.
 *
 * Not a general-purpose surface: the conversation is named by `callback_ref`,
 * the signed handle minted inside each run, so the only caller that can use
 * this is one that received a run. Everything the endpoint decides lives in
 * AiProactiveMessageService; this only shapes the request.
 */
class ConversationMessageController extends Controller
{
    public function __construct(
        private AiProactiveMessageService $proactive,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'callback_ref' => ['required', 'string', 'max:512'],
            'text' => ['required', 'string', 'max:'.$this->maxLength()],

            // The answer sometimes ends the AI's part — a renewal only the team
            // can release. Without this the hub had to choose between a reply
            // that strands the customer and no reply at all.
            'handoff' => ['sometimes', 'boolean'],

            // Free prose, and it lands in an internal note rather than in
            // `conversations.handoff_reason` — see AiProactiveMessageService::handOver.
            'handoff_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // Required, not optional. This is precisely the place where a retry
        // after a timeout becomes a second message in somebody's WhatsApp, and
        // the caller's own retry policy makes that a certainty rather than a
        // risk. Refused as a field error so the sender sees which header.
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Envie um cabeçalho Idempotency-Key (até 191 caracteres) com o id do evento; repita-o em cada retentativa.',
            ]);
        }

        // Rejected here rather than by the channel: a body of spaces reaches
        // WhatsApp as an error whose text explains nothing.
        if (trim($data['text']) === '') {
            throw ValidationException::withMessages([
                'text' => 'O texto da mensagem não pode ser vazio.',
            ]);
        }

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $result = $this->proactive->send($key->tenant, $key, $idempotencyKey, $data);

        return response()->json($result['body'], $result['status']);
    }

    private function maxLength(): int
    {
        return max(1, (int) config('ai.proactive.max_length', 4096));
    }
}
