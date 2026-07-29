<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Http\Controllers\Controller;
use App\Models\AiSuggestAgent;
use App\Models\Conversation;
use App\Services\AiSuggest\AiSuggestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AiSuggestController extends Controller
{
    /**
     * List the tenant's "Respond with AI" agents. API keys are never returned
     * — only whether one is set and a short preview.
     */
    public function agents()
    {
        $agents = Auth::user()->tenant->aiSuggestAgents()
            ->withCount('connections')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $agents->map(fn (AiSuggestAgent $agent) => $this->presentAgent($agent)),
            'meta' => [
                'providers' => AiSuggestService::PROVIDERS,
                'default_models' => AiSuggestService::DEFAULT_MODELS,
            ],
        ]);
    }

    public function storeAgent(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in(AiSuggestService::PROVIDERS)],
            'api_key' => ['required', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $agent = Auth::user()->tenant->aiSuggestAgents()->create([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'api_key' => trim($validated['api_key']),
            'model' => trim((string) ($validated['model'] ?? '')) ?: null,
        ]);

        return response()->json([
            'message' => 'AI agent created',
            'data' => $this->presentAgent($agent),
        ], 201);
    }

    public function updateAgent(Request $request, int $id)
    {
        $agent = Auth::user()->tenant->aiSuggestAgents()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in(AiSuggestService::PROVIDERS)],
            // Optional on update — blank keeps the stored key.
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $apiKey = trim((string) ($validated['api_key'] ?? ''));

        $agent->update([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'model' => trim((string) ($validated['model'] ?? '')) ?: null,
            ...($apiKey !== '' ? ['api_key' => $apiKey] : []),
        ]);

        return response()->json([
            'message' => 'AI agent updated',
            'data' => $this->presentAgent($agent),
        ]);
    }

    /**
     * Deleting an agent silently unlinks its connections (FK null on delete),
     * turning the feature off for them.
     */
    public function destroyAgent(int $id)
    {
        $agent = Auth::user()->tenant->aiSuggestAgents()->findOrFail($id);
        $agent->delete();

        return response()->json([
            'message' => 'AI agent deleted',
        ]);
    }

    /**
     * Draft a reply suggestion for the conversation. The text is returned to
     * the composer only — the agent reviews, edits and sends it manually.
     */
    public function suggest(int $id, AiSuggestService $service)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

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

    protected function presentAgent(AiSuggestAgent $agent): array
    {
        $apiKey = (string) $agent->api_key;

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'provider' => $agent->provider,
            'model' => $agent->model,
            'api_key_set' => $apiKey !== '',
            'api_key_preview' => $apiKey !== '' ? '••••' . substr($apiKey, -4) : null,
            'connections_count' => $agent->connections_count ?? $agent->connections()->count(),
        ];
    }
}
