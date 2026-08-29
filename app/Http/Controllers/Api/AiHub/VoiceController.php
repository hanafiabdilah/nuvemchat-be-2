<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Controller;
use App\Services\AiAgentHub\ElevenLabsVoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceController extends Controller
{
    /**
     * The ElevenLabs voices a flow node can speak with.
     *
     * Deliberately not tenant-scoped, unlike everything else under /ai-hub:
     * this is the shared library, identical for every account, and reaching it
     * needs no credential of anybody's. A workspace's own cloned voices are
     * not here — those live behind the account key, which is held by the hub.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('refresh')) {
            ElevenLabsVoices::forget();
        }

        return response()->json([
            'data' => ElevenLabsVoices::all(),
        ]);
    }
}
