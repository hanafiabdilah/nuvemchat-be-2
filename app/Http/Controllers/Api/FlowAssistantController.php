<?php

namespace App\Http\Controllers\Api;

use App\Enums\Integration\IntegrationCategory;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\AiHubAgent;
use App\Models\Flow;
use App\Models\FlowAssistantMessage;
use App\Models\FlowEdge;
use App\Models\GalleryAsset;
use App\Models\Integration;
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
    /**
     * How much of the thread is replayed to the model.
     *
     * The transcript is now durable and shared, so it grows without limit — but
     * a turn should not get more expensive every time somebody asks another
     * question. The tail is what carries the current line of thought.
     */
    private const HISTORY_TURNS = 12;

    /** Files a single request may hand the assistant to build with. */
    private const MAX_GALLERY_ASSETS = 12;

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
     * The flow's assistant thread.
     *
     * Per flow, not per person: whoever can edit the flow reads why it looks
     * the way it does. An agent opening a flow a colleague built with the
     * assistant last week gets the reasoning, not an empty box.
     */
    public function messages(int $id): JsonResponse
    {
        $flow = $this->flow($id);

        $messages = FlowAssistantMessage::forFlow($flow->id, auth()->user()->tenant_id)
            ->with('user:id,name')
            ->get();

        return response()->json([
            'data' => $messages->map(fn (FlowAssistantMessage $message) => [
                'id' => (string) $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'flow' => $message->blueprint,
                'warnings' => $message->warnings ?? [],
                'author' => $message->user?->name,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Clear the thread.
     *
     * Deliberately available: the transcript is shared, so a session full of
     * false starts is something a colleague has to read past. Only the
     * conversation goes — the flow it produced is a separate thing and stays.
     */
    public function clear(int $id): JsonResponse
    {
        $flow = $this->flow($id);

        FlowAssistantMessage::where('flow_id', $flow->id)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->delete();

        return response()->json(['data' => ['cleared' => true]]);
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

        // Everything that touches the database happens here, before the
        // response starts streaming: inside the callback the headers are
        // already sent, so a query that throws there has no status code left to
        // fail with.
        $context = $this->context($flow, $input['gallery_asset_ids']);
        $history = $this->history($flow);
        $this->record($flow, 'user', $input['message']);

        return response()->stream(function () use ($input, $context, $history, $flow) {
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
                $result = $this->assistant->ask($input['message'], $context, $history, $emit);

                $message = $this->record($flow, 'assistant', $result['reply'], $result['flow'], $result['warnings']);
                $result['id'] = (string) $message->id;

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

        $context = $this->context($flow, $input['gallery_asset_ids']);
        $history = $this->history($flow);
        $this->record($flow, 'user', $input['message']);

        $result = $this->assistant->ask($input['message'], $context, $history);

        $message = $this->record($flow, 'assistant', $result['reply'], $result['flow'], $result['warnings']);
        $result['id'] = (string) $message->id;

        return response()->json(['data' => $result]);
    }

    private function flow(int $id): Flow
    {
        return Flow::with('nodes')
            ->where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($id);
    }

    /**
     * @return array{message: string, gallery_asset_ids: list<int>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:' . FlowAssistantConfig::MAX_MESSAGE_CHARS],
            // Ids, never URLs — the same reason `gallery_asset_id` is an id on
            // every send route (see GalleryMediaResolver). A URL the client
            // supplied is a URL the client chose, and it would end up baked
            // into a saved flow that sends it to customers.
            'gallery_asset_ids' => ['nullable', 'array', 'max:' . self::MAX_GALLERY_ASSETS],
            'gallery_asset_ids.*' => ['integer'],
        ]);

        return [
            'message' => $validated['message'],
            'gallery_asset_ids' => array_values(array_unique($validated['gallery_asset_ids'] ?? [])),
        ];
    }

    /**
     * The tail of the thread, as the model sees it.
     *
     * Read from the database rather than taken from the request. It used to be
     * posted back by the browser, which made it per-tab — and also made it
     * client input that shaped a paid request. Now it is the same thread every
     * agent on this flow is reading.
     *
     * Blueprints are deliberately left out: they are enormous, they are stale
     * the moment the flow changes, and the current flow is sent separately.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Flow $flow): array
    {
        return FlowAssistantMessage::forFlow($flow->id, auth()->user()->tenant_id)
            ->latest('id')
            ->limit(self::HISTORY_TURNS)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (FlowAssistantMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    private function record(
        Flow $flow,
        string $role,
        string $content,
        ?array $blueprint = null,
        array $warnings = [],
    ): FlowAssistantMessage {
        return FlowAssistantMessage::create([
            'flow_id' => $flow->id,
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'role' => $role,
            'content' => $content,
            'blueprint' => $blueprint,
            'warnings' => $warnings ?: null,
        ]);
    }

    /**
     * What the model is allowed to build with: this workspace's real tags,
     * agents, AI agents and picked media, plus the flow as it stands.
     *
     * Sending the real ids is what makes "marque a conversa como urgente" work
     * — and, just as important, what makes asking for a tag that does not exist
     * fail as a sentence in the reply instead of as a validation error on save.
     * The model is told in the prompt never to invent one; the validator is
     * what enforces it.
     *
     * @param  list<int>  $galleryAssetIds
     */
    private function context(Flow $flow, array $galleryAssetIds = []): array
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
            'gallery' => $this->galleryContext($tenantId, $galleryAssetIds),
            // The accounts a payment or pixel node may point at, and the flows a
            // go-to-flow node may continue in. This is the only place the model
            // can learn a valid id, and an invented one is refused on save.
            'payment_integrations' => $this->integrationContext($tenantId, IntegrationCategory::Payment),
            'pixel_integrations' => $this->integrationContext($tenantId, IntegrationCategory::Pixel),
            'flows' => Flow::where('tenant_id', $tenantId)
                ->whereKeyNot($flow->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Flow $other) => ['id' => $other->id, 'name' => $other->name])
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
     * Enabled integrations of one kind, named the way the person knows them.
     * `payment_methods` rides along because whether "checkout" exists depends
     * on the provider, and a node asking OpenPix for a card link fails at the
     * moment a customer is waiting to pay.
     *
     * @return list<array<string, mixed>>
     */
    private function integrationContext(int $tenantId, IntegrationCategory $category): array
    {
        return Integration::forTenant($tenantId)
            ->inCategory($category)
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Integration $integration) => array_filter([
                'id' => $integration->id,
                'name' => $integration->name,
                'provider' => $integration->provider->label(),
                'payment_methods' => $integration->provider->paymentMethods() ?: null,
            ]))
            ->values()
            ->all();
    }

    /**
     * The media the person picked, resolved to the URLs a flow node can carry.
     *
     * ⚠️ Resolved here, from ids, and never taken from the request. A gallery
     * URL is permanent and signed, so it is safe to put in a flow that will
     * send it for months — but only because this is the one place that checks
     * the file belongs to this workspace. The alternative (the browser posting
     * the URL it already has) would let a saved flow point anywhere, and there
     * would be no moment at which the platform knew a library file was in use.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function galleryContext(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return GalleryAsset::forTenant($tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (GalleryAsset $asset) => [
                'name' => $asset->name,
                // The node field names, so the model does not have to map
                // "image" onto a message type itself.
                'message_type' => $asset->type->value,
                'url' => $asset->publicUrl(),
                'mime_type' => $asset->mime_type,
            ])
            ->values()
            ->all();
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
