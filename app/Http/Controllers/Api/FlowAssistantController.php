<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\AiHubAgent;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Tag;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\FlowAssistant\FlowAssistantConfig;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The flow builder's AI assistant, from the workspace side.
 *
 * Two endpoints for one operation, deliberately. {@see stream()} is what the
 * builder uses: a turn can take half a minute when a blueprint needs repairing,
 * and a spinner that says nothing for thirty seconds is indistinguishable from
 * a hang — so the real milestones (thinking → validating → repairing) go out as
 * they happen. {@see ask()} is the same work as one JSON response, for when the
 * stream cannot get through: SSE dies quietly behind buffering proxies, and a
 * feature that is merely slower is much better than one that appears broken.
 */
class FlowAssistantController extends Controller
{
    public function __construct(
        private readonly FlowAssistantService $assistant,
    ) {}

    /**
     * Whether the assistant is available at all, so the builder can decide
     * whether to show the panel.
     *
     * Separate from the plan feature check (that is middleware, and a plan
     * without the feature never reaches here): this answers the other half —
     * whether the platform has finished setting it up. The two read the same
     * to a customer and are fixed in completely different places, so the
     * builder shows a plain "unavailable" rather than a wrong instruction.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                'available' => FlowAssistantConfig::ready(),
            ],
        ]);
    }

    /**
     * One turn, streamed as Server-Sent Events.
     *
     * Events: `status` (stage changes), `result` ({reply, flow, warnings}),
     * `error` ({message}). The connection closes after `result` or `error`.
     */
    public function stream(int $id, Request $request): StreamedResponse
    {
        $flow = $this->flow($id);
        $input = $this->validated($request);
        $context = $this->context($flow);

        return response()->stream(function () use ($input, $context) {
            $emit = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

                // PHP-FPM buffers until the handler returns, which would
                // deliver every stage at once at the end — the exact opposite
                // of the point. The @ is for the case where no buffer is open.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $result = $this->assistant->ask($input['message'], $context, $input['history'], $emit);
                $emit('result', $result);
            } catch (\Throwable $e) {
                // The stream has already sent 200 and its headers, so there is
                // no status code left to fail with — the error has to travel as
                // an event. Whatever the exception carries for a customer is
                // already translated (UpstreamServiceException) or is ours to
                // hide.
                $emit('error', [
                    'message' => $e instanceof UpstreamServiceException
                        ? $e->getMessage()
                        : __('The assistant could not complete this request.'),
                ]);

                report($e);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // Tells nginx and Caddy not to buffer this response. Without it the
            // stage events queue up in the proxy and arrive together at the end.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** The same turn as a single JSON response. */
    public function ask(int $id, Request $request): JsonResponse
    {
        $flow = $this->flow($id);
        $input = $this->validated($request);

        $result = $this->assistant->ask($input['message'], $this->context($flow), $input['history']);

        return response()->json(['data' => $result]);
    }

    private function flow(int $id): Flow
    {
        return Flow::with('nodes')
            ->where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($id);
    }

    /**
     * @return array{message: string, history: list<array{role: string, content: string}>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:' . FlowAssistantConfig::MAX_MESSAGE_CHARS],
            // The transcript is held by the browser and sent back, because
            // turns are stateless server-side (see FlowAssistantService). It is
            // the person's own conversation with themselves, so there is
            // nothing to leak — but it is still client input, hence the caps.
            'history' => ['nullable', 'array', 'max:' . FlowAssistantConfig::MAX_HISTORY_TURNS],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:' . FlowAssistantConfig::MAX_MESSAGE_CHARS],
        ]);

        return [
            'message' => $validated['message'],
            'history' => $validated['history'] ?? [],
        ];
    }

    /**
     * What the model is allowed to build with: this workspace's real tags,
     * agents and AI agents, plus the flow as it stands.
     *
     * Sending the real ids is what makes "marque a conversa como urgente" work
     * — and, just as important, what makes asking for a tag that does not exist
     * fail as a sentence in the reply instead of as a validation error on save.
     * The model is told in the prompt never to invent one; the validator is
     * what enforces it.
     */
    private function context(Flow $flow): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            'flow' => $this->exportFlow($flow),
            'tags' => Tag::where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->all(),
            'agents' => User::where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
            'ai_agents' => AiHubAgent::query()
                ->whereIn('ai_hub_tenant_id', fn ($query) => $query
                    ->select('id')
                    ->from('ai_hub_tenants')
                    ->where('tenant_id', $tenantId))
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (AiHubAgent $agent) => ['id' => $agent->id, 'name' => $agent->name])
                ->all(),
            // Which channels this flow actually drives. It decides whether the
            // interactive node is on the table at all — a WhatsApp button block
            // proposed for a Telegram flow is refused by the save endpoint, and
            // the assistant should never have offered it.
            'channels' => $flow->connections()
                ->pluck('channel')
                ->map(fn ($channel) => $channel instanceof \BackedEnum ? $channel->value : (string) $channel)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * The flow in the same envelope the assistant returns, so "here is the flow"
     * and "here is your new flow" are the same shape in both directions —
     * which is what lets the model keep the keys of nodes it is not touching.
     */
    private function exportFlow(Flow $flow): array
    {
        $nodeIds = $flow->nodes->pluck('id');

        $edges = FlowEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->get();

        return [
            'name' => $flow->name,
            'nodes' => $flow->nodes->map(fn ($node) => [
                'key' => (string) $node->id,
                'type' => $node->type->value,
                'data' => $node->data,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
            ])->values()->all(),
            'edges' => $edges->map(fn (FlowEdge $edge) => [
                'source_key' => (string) $edge->source_node_id,
                'target_key' => (string) $edge->target_node_id,
                'condition_value' => $edge->condition_value,
            ])->values()->all(),
        ];
    }

    /**
     * The format reference, for the builder's "what can it do?" panel.
     *
     * Same string the model is given — so what the assistant is told and what
     * the customer is shown can never describe two different products.
     */
    public function specification(): JsonResponse
    {
        return response()->json([
            'data' => ['specification' => FlowBlueprint::specification()],
        ]);
    }
}
