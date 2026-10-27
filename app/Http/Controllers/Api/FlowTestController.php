<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Models\AiHubAgent;
use App\Models\Connection;
use App\Models\Flow;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiConversationContext;
use App\Services\AiAgentHub\AiFirstMessage;
use App\Services\BusinessHours;
use App\Services\Flow\HttpRequestNodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The flow builder's test panel: the few steps of a test the browser cannot
 * take by itself.
 *
 * A test runs in the browser, against the canvas as it is on screen (saved or
 * not), and touches nothing: no message reaches a channel, no tag, lead or
 * charge is written, nobody is assigned. That is what makes it safe to press
 * on a flow customers are being routed through right now. Two kinds of step
 * cannot be pretended, though, because what they return is the thing being
 * tested — so they come here, and run for real:
 *
 *  - an AI node's turn (`aiTurn`), a real run on the node's agent, billed like
 *    any other (AiAgentHubTenantService::runFlowTest);
 *  - an HTTP Request node's call (`http`), made through the same
 *    HttpRequestNodes the engine uses, address guard and all — and only when
 *    the author asks for it, since the endpoint is somebody's real system.
 *
 * Both take the values already resolved on screen rather than a node id: the
 * node may not be saved yet, and the variables only exist in the test.
 */
class FlowTestController extends Controller
{
    use ResolvesAiHubTenant;

    /** How much of a response body is shown in the panel. */
    private const BODY_PREVIEW_BYTES = 20000;

    public function __construct(private AiAgentHubTenantService $hub) {}

    /**
     * What the test needs to know about where the flow runs: the AI node's
     * default grouping window, and — from the first connection using this
     * flow — whether it is inside its service hours right now and whether its
     * channel draws buttons. Both are only starting values: the panel lets the
     * author flip either to try the other path.
     */
    public function settings(int $id): JsonResponse
    {
        $flow = $this->flow($id);

        $connection = Connection::where('tenant_id', $flow->tenant_id)
            ->where('flow_id', $flow->id)
            ->orderBy('id')
            ->first();

        return response()->json([
            'data' => [
                'ai_turn_delay_seconds' => (int) config('ai.turn_delay_seconds', 8),
                'max_turn_delay_seconds' => (int) config('ai.max_turn_delay_seconds', 300),
                'service_hours_open' => $connection ? BusinessHours::isOpen($connection) : true,
                'away_message' => $connection ? BusinessHours::awayMessage($connection) : null,
                'connection' => $connection ? [
                    'name' => $connection->name,
                    'channel' => $connection->channel->value,
                    'interactive' => $connection->channel->supportsInteractiveMessages(),
                ] : null,
            ],
        ]);
    }

    /**
     * Whether the customer's opening carries something for the AI to answer,
     * or is a greeting the node's welcome answers on its own.
     *
     * Asked of the server rather than worked out in the browser because the
     * answer is AiFirstMessage's word lists, and a second copy of those would
     * be a test of a different rule.
     */
    public function opening(Request $request, int $id): JsonResponse
    {
        $this->flow($id);

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*' => ['nullable', 'string', 'max:4000'],
        ]);

        $needsAnswer = collect($validated['messages'])
            ->contains(fn ($text) => ! AiFirstMessage::isGreetingOnly((string) $text));

        return response()->json(['data' => ['needs_answer' => $needsAnswer]]);
    }

    /**
     * One AI turn of the test conversation.
     *
     * The input is put together the way runAIAgentTurn puts it together — the
     * customer's burst joined by line, what the agent has not seen yet ahead of
     * it, and the "your welcome is right above this" note when the welcome was
     * held for this answer — so the agent is asked the same question it would
     * be asked in a real thread.
     */
    public function aiTurn(Request $request, int $id): JsonResponse
    {
        $flow = $this->flow($id);

        $validated = $request->validate([
            'ai_hub_agent_id' => ['required', 'integer'],
            // The test session's own hub conversation, kept for the whole test
            // so the agent remembers its earlier turns.
            'session' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*' => ['nullable', 'string', 'max:4000'],
            'transcript' => ['nullable', 'array', 'max:60'],
            'transcript.*.speaker' => ['required', 'string', Rule::in(['customer', 'automated', 'welcome'])],
            'transcript.*.text' => ['required', 'string', 'max:4000'],
            'welcome' => ['nullable', 'string', 'max:4000'],
            'contact_name' => ['nullable', 'string', 'max:120'],
        ]);

        $agent = $this->agent((int) $validated['ai_hub_agent_id']);

        $text = trim(collect($validated['messages'])
            ->map(fn ($body) => trim((string) $body))
            ->filter(fn (string $body) => $body !== '')
            ->implode("\n"));

        if ($text === '') {
            return response()->json([
                'message' => 'There is nothing for the agent to answer.',
                'code' => 'empty_input',
            ], 422);
        }

        $input = AiConversationContext::compose(
            AiConversationContext::fromTranscript($validated['transcript'] ?? []),
            $text,
        );

        $input = AiConversationContext::precededByWelcome((string) ($validated['welcome'] ?? ''), $input);

        // The workspace as well as the session: Pingly is one tenant on the
        // hub, so every workspace's test keys share one namespace there.
        $conversationKey = sprintf('flow-test-%d-%d-%s', $flow->tenant_id, $flow->id, $validated['session']);

        try {
            $run = $this->hub->runFlowTest(
                $agent,
                $conversationKey,
                $input,
                $flow->id,
                trim((string) ($validated['contact_name'] ?? '')) ?: 'Cliente teste',
            );
        } catch (CreditExhaustedException $e) {
            return response()->json([
                'message' => 'Your credit balance is empty. Top it up to keep using AI.',
                'code' => 'credit_exhausted',
                'balance_cents' => $e->balanceCents,
            ], 402);
        } catch (UpstreamServiceException $e) {
            // Already translated and logged with a reference by UpstreamError.
            return $e->toResponse();
        }

        $details = (array) ($run->handoff_details ?? []);

        return response()->json([
            'data' => [
                'reply' => trim((string) $run->output_message) ?: null,
                'handoff' => (bool) $run->handoff_triggered,
                // The same reading FlowExecutor::handleAIAgentInput makes.
                'handoff_reason' => $run->handoff_triggered
                    ? (string) ($details['trigger'] ?? $details['reason'] ?? 'ai_requested')
                    : null,
                'run_id' => $run->id,
            ],
        ]);
    }

    /**
     * Make an HTTP Request node's call for real, with the values on screen.
     *
     * The node's fields arrive already interpolated by the test — the
     * variables exist only there — and go through HttpRequestNodes exactly as
     * the engine sends them: the same address guard, redirect guard, timeout
     * clamp and response mapping.
     */
    public function http(Request $request, int $id): JsonResponse
    {
        $flow = $this->flow($id);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'url' => ['nullable', 'string', 'max:2048'],
            'headers' => ['nullable', 'array', 'max:30'],
            'headers.*.key' => ['nullable', 'string', 'max:200'],
            'headers.*.value' => ['nullable', 'string', 'max:4000'],
            'body' => ['nullable', 'string', 'max:65535'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'response_mappings' => ['nullable', 'array', 'max:30'],
            'response_mappings.*.path' => ['nullable', 'string', 'max:255'],
            'response_mappings.*.variable' => ['nullable', 'string', 'max:100'],
        ]);

        $result = HttpRequestNodes::perform(
            $validated,
            fn (string $value) => $value,
            logContext: ['flow_id' => $flow->id, 'purpose' => 'flow_test'],
        );

        $body = $result['body'];
        $truncated = $body !== null && strlen($body) > self::BODY_PREVIEW_BYTES;

        return response()->json([
            'data' => [
                'branch' => $result['branch'],
                'status' => $result['status'],
                'body' => $truncated ? mb_strcut($body, 0, self::BODY_PREVIEW_BYTES) : $body,
                'body_truncated' => $truncated,
                // Values as the engine would store them; a test shows them as text.
                'mapped' => array_map(
                    fn ($value) => $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value)),
                    $result['mapped'],
                ),
                'error' => $result['error'],
            ],
        ]);
    }

    private function flow(int $id): Flow
    {
        return Flow::where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);
    }

    private function agent(int $id): AiHubAgent
    {
        return $this->aiHubTenant()->agents()->findOrFail($id);
    }
}
