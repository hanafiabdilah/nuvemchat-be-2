<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Flow\FlowStateStatus;
use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowState;
use App\Observers\ConversationObserver;
use App\Services\Conversation\SystemMessage;
use App\Services\Flow\FlowExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * A person steering the automation of one conversation by hand: start a flow
 * of their choosing, pause the one that is running, resume it.
 *
 * Until now a flow only ever started by itself (the connection's flow, on the
 * customer's first message) and only ever ended by itself or by somebody
 * accepting the thread. These are the manual counterparts.
 *
 * One rule shapes all three: a flow runs only in a conversation that belongs
 * to nobody (Pending, or AI-handling). That is what every guard in the engine
 * assumes, and it is why starting a flow in a conversation an agent is holding
 * hands it back — the agent is choosing to let the bot take it from here.
 */
class ConversationFlowController extends Controller
{
    public const INFO_TRIGGERED = 'flow_triggered_by';

    public const INFO_PAUSED = 'flow_paused_by';

    public const INFO_RESUMED = 'flow_resumed_by';

    /**
     * The flows this conversation could be sent through.
     *
     * Its own endpoint rather than GET /flows because the person doing this is
     * an attendant, who very often cannot open the flow builder at all — and
     * needs nothing from it but the names.
     */
    public function options(int $id): JsonResponse
    {
        $conversation = Conversation::visibleTo(Auth::user())->with('connection')->findOrFail($id);

        return response()->json([
            'data' => Flow::where('tenant_id', $conversation->connection->tenant_id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Flow $flow) => ['id' => $flow->id, 'name' => $flow->name])
                ->values(),
        ]);
    }

    public function trigger(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        if ($refusal = $this->refuse($conversation)) {
            return $refusal;
        }

        $tenantId = $conversation->connection->tenant_id;

        $data = $request->validate([
            'flow_id' => ['required', 'integer', Rule::exists('flows', 'id')->where('tenant_id', $tenantId)],
        ]);

        $flow = Flow::where('tenant_id', $tenantId)->findOrFail($data['flow_id']);
        $actor = Auth::user();

        // Two people pressing the button on the same thread would otherwise
        // both move the one flow state and both run a start node.
        $lock = Cache::lock("conversation-flow:{$conversation->id}", 30);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Another flow action is in progress for this conversation.',
                'code' => 'flow_action_in_progress',
            ], 409);
        }

        try {
            if (! $flow->nodes()->where('type', \App\Enums\Flow\NodeType::Start)->exists()) {
                return response()->json([
                    'message' => 'This flow has no start step.',
                    'code' => 'flow_has_no_start',
                ], 422);
            }

            // A flow only speaks in a conversation nobody holds. The note below
            // says who did this and why the thread left their hands, so the
            // automatic "Active → Pending" line would only repeat it.
            if ($conversation->status === Status::Active) {
                ConversationObserver::withoutStatusNote(function () use ($conversation) {
                    $conversation->status = Status::Pending;
                    $conversation->user_id = null;
                    $conversation->needs_human = false;
                    $conversation->save();
                });
            }

            SystemMessage::info(
                $conversation,
                "{$actor->name} started the flow \"{$flow->name}\".",
                self::INFO_TRIGGERED,
                ['by' => $actor->name, 'flow' => $flow->name],
            );

            try {
                (new FlowExecutor)->triggerFlow($conversation, $flow);
            } catch (\Throwable $th) {
                // The flow was started and the thread already says so; a step
                // that blew up part way is the flow's failure, not this
                // request's.
                report($th);
                Log::error('Manually started flow failed while running', [
                    'conversation_id' => $conversation->id,
                    'flow_id' => $flow->id,
                    'error' => $th->getMessage(),
                ]);
            }
        } finally {
            $lock->release();
        }

        return $this->done($conversation, 'Flow started');
    }

    public function pause(int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        if ($refusal = $this->refuse($conversation)) {
            return $refusal;
        }

        if (! (new FlowExecutor)->pauseFlow($conversation)) {
            return response()->json([
                'message' => 'There is no flow running in this conversation.',
                'code' => 'flow_not_running',
            ], 409);
        }

        $actor = Auth::user();

        SystemMessage::info(
            $conversation,
            "{$actor->name} paused the flow.",
            self::INFO_PAUSED,
            ['by' => $actor->name],
        );

        return $this->done($conversation, 'Flow paused');
    }

    public function resume(int $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        if ($refusal = $this->refuse($conversation)) {
            return $refusal;
        }

        $paused = FlowState::where('conversation_id', $conversation->id)
            ->where('status', FlowStateStatus::Paused->value)
            ->exists();

        if (! $paused) {
            return response()->json([
                'message' => 'There is no paused flow in this conversation.',
                'code' => 'flow_not_paused',
            ], 409);
        }

        // Somebody accepted the thread since it was paused; the flow has no
        // place to speak any more. Starting one again is the explicit action.
        if (! in_array($conversation->status, Status::flowEligible(), true)) {
            return response()->json([
                'message' => 'This conversation is being handled by a person, so the flow cannot resume.',
                'code' => 'conversation_not_in_queue',
            ], 409);
        }

        $actor = Auth::user();

        // Written first, so whatever the flow sends next lands under it.
        SystemMessage::info(
            $conversation,
            "{$actor->name} resumed the flow.",
            self::INFO_RESUMED,
            ['by' => $actor->name],
        );

        try {
            (new FlowExecutor)->resumePausedFlow($conversation);
        } catch (\Throwable $th) {
            report($th);
            Log::error('Resumed flow failed while running', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);
        }

        return $this->done($conversation, 'Flow resumed');
    }

    private function conversation(int $id): Conversation
    {
        return Conversation::visibleTo(Auth::user())->with(['connection', 'agent'])->findOrFail($id);
    }

    /**
     * What stands between this person and this conversation's automation.
     * Returns null when nothing does.
     */
    private function refuse(Conversation $conversation): ?JsonResponse
    {
        $user = Auth::user();

        // Flows never run in groups, and e-mail is a shared inbox with no
        // queue for a flow to answer from.
        if ($conversation->isGroup() || $conversation->connection?->channel === Channel::Email) {
            return response()->json([
                'message' => 'Flows are not available for this kind of conversation.',
                'code' => 'flow_not_supported',
            ], 422);
        }

        if ($conversation->status === Status::Resolved) {
            return response()->json([
                'message' => 'This conversation is closed.',
                'code' => 'conversation_resolved',
            ], 422);
        }

        if (! $conversation->isReadableBy($user)) {
            return response()->json([
                'message' => 'This conversation is exclusive to another agent.',
                'code' => 'conversation_exclusive',
                'agent' => $conversation->agent?->name,
            ], 403);
        }

        // A thread in the queue is anybody's to act on, exactly as accepting
        // it is. One a colleague is holding is theirs.
        if ($conversation->status === Status::Active
            && $conversation->user_id !== null
            && ! $conversation->isAccessibleBy($user)) {
            return response()->json([
                'message' => 'This conversation is being handled by another agent.',
                'code' => 'conversation_not_yours',
                'agent' => $conversation->agent?->name,
            ], 403);
        }

        return null;
    }

    private function done(Conversation $conversation, string $message): JsonResponse
    {
        $conversation = $conversation->fresh(['connection', 'agent', 'flowState']);

        // The flow state is its own table: without this bump a dashboard that
        // was offline would never be told the conversation changed.
        $conversation->touch();

        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => $message,
            'data' => new ConversationResource($conversation),
        ]);
    }
}
