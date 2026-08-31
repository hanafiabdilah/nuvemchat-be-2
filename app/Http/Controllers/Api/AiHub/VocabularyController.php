<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Controller;
use App\Services\AiAgentHub\AiVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace's audio vocabulary: the words a transcription model gets wrong
 * because it has never heard of this business.
 *
 * On the tenant, not on an agent or a flow node, because that is how the words
 * vary — and because "Respond with AI" transcribes voice notes with no node
 * behind it at all, so a node-level list would be invisible exactly where an
 * agent is reading the transcript by hand.
 */
class VocabularyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'data' => [
                'terms' => AiVocabulary::dictionary($tenant),
                // What the platform already listens for, shown read-only so
                // nobody spends a slot re-typing a word that is covered.
                'platform_terms' => array_values((array) config('ai.audio.keyterms', [])),
                'max_terms' => AiVocabulary::MAX_TERMS,
                'max_aliases' => AiVocabulary::MAX_ALIASES,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terms' => ['present', 'array', 'max:' . AiVocabulary::MAX_TERMS],
            'terms.*.term' => ['required', 'string', 'min:2', 'max:50'],
            'terms.*.aliases' => ['sometimes', 'array', 'max:' . AiVocabulary::MAX_ALIASES],
            'terms.*.aliases.*' => ['string', 'min:2', 'max:50'],
        ]);

        $tenant = $request->user()->tenant;

        // Validated for the message it gives back, sanitised for what is
        // stored: the rules that dedupe and trim live in one place, and the
        // read path applies them to old rows anyway.
        $terms = AiVocabulary::sanitize($validated['terms']);

        $tenant->forceFill(['audio_dictionary' => $terms ?: null])->save();

        return response()->json([
            'data' => ['terms' => $terms],
        ]);
    }
}
