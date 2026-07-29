<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AiSuggest\AiSuggestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AiSuggestController extends Controller
{
    /**
     * Tenant-level provider configuration for "Respond with AI".
     * The API key is never returned — only whether one is set and a preview.
     */
    public function settings()
    {
        $config = Auth::user()->tenant->ai_suggest_config ?? [];
        $apiKey = (string) ($config['api_key'] ?? '');

        return response()->json([
            'data' => [
                'provider' => $config['provider'] ?? null,
                'model' => $config['model'] ?? null,
                'api_key_set' => $apiKey !== '',
                'api_key_preview' => $apiKey !== '' ? '••••' . substr($apiKey, -4) : null,
                'providers' => AiSuggestService::PROVIDERS,
                'default_models' => AiSuggestService::DEFAULT_MODELS,
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(AiSuggestService::PROVIDERS)],
            'model' => ['nullable', 'string', 'max:100'],
            // Optional when a key is already stored — blank keeps the current one.
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant = Auth::user()->tenant;
        $current = $tenant->ai_suggest_config ?? [];

        $apiKey = trim((string) ($validated['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) ($current['api_key'] ?? '');
        }

        if ($apiKey === '') {
            return response()->json([
                'message' => 'An API key is required.',
            ], 422);
        }

        $tenant->ai_suggest_config = [
            'provider' => $validated['provider'],
            'api_key' => $apiKey,
            'model' => trim((string) ($validated['model'] ?? '')) ?: null,
        ];
        $tenant->save();

        return response()->json([
            'message' => 'AI suggestion settings saved',
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

        if(!$conversation->connection->ai_suggest_enabled){
            return response()->json([
                'message' => 'AI suggestions are not enabled for this connection',
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
