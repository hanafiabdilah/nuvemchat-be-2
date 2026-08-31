<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Exceptions\Billing\AiRunQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AiSuggest\AiSuggestService;
use App\Services\Live\LiveActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AiSuggestController extends Controller
{
    /**
     * Draft a reply suggestion for the conversation. The text is returned to
     * the composer only — the agent reviews, edits and sends it manually.
     *
     * Runs on the AI Hub agent linked to the connection — the same agents the
     * flow AIAgent nodes use, managed on the AI Agent page.
     */
    public function suggest(int $id, AiSuggestService $service)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        if(!$conversation->connection->ai_suggest_agent_id){
            return response()->json([
                'message' => 'No AI agent is linked to this connection',
            ], 400);
        }

        // Announced to the other agents, not just run. The suggestion lands in
        // one person's composer, but the seconds it takes look identical to a
        // thread nobody has picked up — and that is exactly when a second agent
        // starts writing the same reply by hand.
        LiveActivity::aiSuggest($conversation, Auth::user());

        try {
            $suggestion = $service->suggest($conversation);
        } catch (AiRunQuotaExceededException $th) {
            // 402, not 502: nothing is broken, the plan's AI runs are spent.
            // The agent can still write the reply themselves, so this is a
            // disabled button and a note — not an error state.
            return response()->json([
                'message' => 'Your plan\'s AI runs for this billing period are used up.',
                'code' => 'ai_quota_exceeded',
                'limit' => $th->limit,
                'used' => $th->used,
            ], 402);
        } catch (\Throwable $th) {
            Log::warning('AiSuggest: failed to generate suggestion', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => $th->getMessage(),
            ], 502);
        } finally {
            // Every exit clears it, including the two failures: an indicator
            // left running by the quota path would say the AI is working on a
            // thread it has just refused to touch.
            LiveActivity::idle($conversation);
        }

        return response()->json([
            'data' => [
                'suggestion' => $suggestion,
            ],
        ]);
    }
}
