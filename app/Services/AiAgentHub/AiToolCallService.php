<?php

namespace App\Services\AiAgentHub;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Exceptions\PublicApiException;
use App\Models\AiToolCall;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Tenant;
use App\Services\AiAgentHub\Tools\AiToolArguments;
use App\Services\AiAgentHub\Tools\AiToolCatalog;
use App\Services\AiAgentHub\Tools\AiToolExecutor;
use App\Services\Flow\AiToolNodes;
use App\Services\Live\LiveActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The AI Hub running one of our tools in the middle of its own run —
 * POST /api/v1/conversations/tools.
 *
 * The sibling of AiProactiveMessageService, and deliberately built the same
 * way, because the same three guarantees are what make it safe:
 *
 *  1. **A valid reference is permission to ask, never permission to act.** The
 *     `callback_ref` proves the run happened; whether the conversation is still
 *     with this agent, on this node, is re-read here every time. An agent who
 *     took the thread over between the run starting and this call keeps it —
 *     the hub gets a 409 and ends the run without replying.
 *  2. **Only tools the node offers.** The node's ticked capabilities decide the
 *     list, read now, from the node as it is now: a capability switched off
 *     mid-conversation stops working on the next call.
 *  3. **A retry never acts twice.** The row is written before the tool runs,
 *     so it exists in the gap a timeout hides in; a retry with the same
 *     Idempotency-Key is answered from it. A charge issued once stays issued
 *     once.
 */
final class AiToolCallService
{
    /** A row whose request died mid-way must not answer "in progress" forever. */
    private const STALE_MINUTES = 5;

    public function __construct(private AiToolExecutor $executor) {}

    /**
     * @param  array{callback_ref: string, tool: string, arguments?: array<string, mixed>|null, run_id?: string|null}  $data  already validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function call(?Tenant $tenant, ?ApiKey $key, string $idempotencyKey, array $data): array
    {
        if (! AiToolNodes::enabled()) {
            throw new PublicApiException(
                'As ferramentas de IA estão desativadas nesta plataforma.',
                'tools_disabled',
                403,
            );
        }

        // The reference first: for the hub, it is the only thing that says
        // which workspace this call belongs to.
        $claims = AiCallbackRef::open($data['callback_ref']);

        $conversation = Conversation::with(['connection.tenant', 'contact'])->find($claims['conversation_id']);

        // The same answer for "another workspace's" and "does not exist":
        // neither confirms anything to a caller probing with a stolen ref.
        if (! $conversation
            || ! $conversation->connection?->tenant
            || ($tenant !== null && $conversation->connection->tenant_id !== $tenant->id)) {
            throw new PublicApiException('Conversa não encontrada.', 'conversation_not_found', 404);
        }

        $tenant ??= $conversation->connection->tenant;

        if ($replay = $this->replay($tenant, $idempotencyKey)) {
            return $replay;
        }

        ['flowState' => $flowState, 'node' => $node] = $this->assertStillWithTheAgent($conversation, $claims);

        $tool = (string) $data['tool'];
        $arguments = (array) ($data['arguments'] ?? []);

        $definition = AiToolCatalog::find($node, $tenant->id, $tool);

        if ($definition === null) {
            throw new PublicApiException(
                "A ferramenta \"{$tool}\" não está ativa nesta etapa do fluxo.",
                'tool_not_enabled',
                422,
            );
        }

        if ($problems = AiToolArguments::problems($definition['parameters'], $arguments)) {
            throw new PublicApiException(
                implode(' ', $problems),
                'invalid_arguments',
                422,
            );
        }

        $this->assertWithinConversationCeiling($conversation);

        try {
            $record = AiToolCall::create([
                'tenant_id' => $tenant->id,
                'api_key_id' => $key?->id,
                'idempotency_key' => $idempotencyKey,
                'conversation_id' => $conversation->id,
                'flow_state_id' => $flowState->id,
                'flow_node_id' => $node->id,
                'ai_hub_agent_id' => $claims['ai_hub_agent_id'],
                'hub_run_id' => isset($data['run_id']) ? Str::limit((string) $data['run_id'], 191, '') : null,
                'tool' => $tool,
                'arguments' => $arguments,
                'status' => AiToolCall::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($tenant, $idempotencyKey)
                ?? throw new PublicApiException(
                    'Esta chamada ainda está em execução. Tente de novo em alguns segundos.',
                    'tool_call_in_progress',
                    409,
                );
        }

        RateLimiter::hit($this->ceilingKey($conversation), 3600);
        LiveActivity::aiTool($conversation, $node, $tool);

        $started = microtime(true);

        try {
            $outcome = $this->executor->run($tool, $arguments, $tenant, $conversation, $flowState, $node);
        } catch (\Throwable $th) {
            // Kept, not deleted: a tool that threw may still have acted — a
            // Pix created at the gateway before our side failed — and a retry
            // must not act again. Held for the stale window, then cleared.
            $record->update([
                'status' => AiToolCall::STATUS_FAILED,
                'error' => Str::limit($th->getMessage(), 480),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            Log::error('AiToolCall: the tool threw', [
                'conversation_id' => $conversation->id,
                'tool' => $tool,
                'error' => $th->getMessage(),
            ]);

            // Answered as a tool result rather than a 500: the model can tell
            // the customer something went wrong, which beats a run that dies.
            $outcome = [
                'ok' => false,
                'result' => ['error' => 'tool_failed'],
                'message_for_model' => 'The tool failed on the shop\'s side. Apologise briefly and offer to hand the customer to the team.',
            ];

            $record->update(['result' => $outcome]);

            return ['status' => 200, 'body' => $outcome];
        }

        $record->update([
            'status' => $outcome['ok'] ? AiToolCall::STATUS_DONE : AiToolCall::STATUS_FAILED,
            'result' => $outcome,
            'error' => $outcome['ok'] ? null : Str::limit((string) ($outcome['result']['error'] ?? ''), 480),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        Log::info('AiToolCall: tool ran', [
            'conversation_id' => $conversation->id,
            'tool' => $tool,
            'ok' => $outcome['ok'],
            'tenant_id' => $tenant->id,
        ]);

        return ['status' => 200, 'body' => $outcome];
    }

    /**
     * Is the conversation still this agent's, on this node?
     *
     * The same four answers as the proactive endpoint, for the same reason:
     * the hub decides what to do next from the code.
     *
     * @return array{flowState: FlowState, node: FlowNode}
     */
    private function assertStillWithTheAgent(Conversation $conversation, array $claims): array
    {
        if ($conversation->status === ConversationStatus::Active) {
            throw new PublicApiException('Um atendente assumiu esta conversa.', 'conversation_with_human', 409);
        }

        if (! in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            throw new PublicApiException('Esta conversa foi encerrada.', 'conversation_closed', 409);
        }

        $flowState = FlowState::with('currentNode')->where('conversation_id', $conversation->id)->first();
        $node = $flowState?->currentNode;

        if (! $flowState
            || $flowState->status !== FlowStateStatus::Running
            || ! $node
            || $node->id !== $claims['flow_node_id']
            || $node->type !== NodeType::AiTools
            || (int) (($node->data ?? [])['ai_hub_agent_id'] ?? 0) !== $claims['ai_hub_agent_id']) {
            throw new PublicApiException(
                'Esta conversa não está mais sendo atendida por este agente de IA.',
                'conversation_not_with_ai',
                409,
            );
        }

        return ['flowState' => $flowState, 'node' => $node];
    }

    private function assertWithinConversationCeiling(Conversation $conversation): void
    {
        $max = max(1, (int) config('ai.tools.max_calls_per_conversation_per_hour', 120));

        if (! RateLimiter::tooManyAttempts($this->ceilingKey($conversation), $max)) {
            return;
        }

        Log::warning('AiToolCall: conversation ceiling reached', [
            'conversation_id' => $conversation->id,
            'max_per_hour' => $max,
        ]);

        throw new PublicApiException(
            'Muitas chamadas de ferramenta nesta conversa na última hora.',
            'too_many_tool_calls',
            429,
            ['retry_after' => RateLimiter::availableIn($this->ceilingKey($conversation))],
        );
    }

    private function ceilingKey(Conversation $conversation): string
    {
        return "ai-tools:{$conversation->id}";
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function replay(Tenant $tenant, string $idempotencyKey): ?array
    {
        $record = AiToolCall::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $record) {
            return null;
        }

        if ($record->result === null) {
            if ($record->created_at?->lt(now()->subMinutes(self::STALE_MINUTES))) {
                $record->delete();

                return null;
            }

            throw new PublicApiException(
                'Esta chamada ainda está em execução. Tente de novo em alguns segundos.',
                'tool_call_in_progress',
                409,
            );
        }

        return ['status' => 200, 'body' => array_merge($record->result, ['duplicate' => true])];
    }
}
