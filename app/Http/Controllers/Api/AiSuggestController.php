<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AiSuggest\AiSuggestService;
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

        try {
            $suggestion = $service->suggest($conversation);
        } catch (\Throwable $th) {
            Log::warning('AiSuggest: failed to generate suggestion', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => $th->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => [
                'suggestion' => $suggestion,
            ],
        ]);
    }
}
