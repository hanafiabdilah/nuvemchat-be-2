<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiTokens\AiModelCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Providers and models for every Back Office form that names one.
 *
 * Shared by the trained-agent blueprint, the token pool's default model and the
 * per-model price editor — all three of which were free-text boxes, and all
 * three of which produced the same failure: a model id that looks right, is
 * accepted, and turns out not to be servable long after the person who typed it
 * has moved on.
 */
class AdminAiModelController extends Controller
{
    public function __construct(
        private readonly AiModelCatalog $catalog,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->catalog->catalog());
    }
}
