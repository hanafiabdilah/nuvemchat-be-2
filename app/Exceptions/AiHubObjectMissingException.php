<?php

namespace App\Exceptions;

use Exception;

/**
 * The hub answered 404 for something our mirror says it holds.
 *
 * Its own class because this is the one hub failure we can answer ourselves.
 * Every other 4xx means we asked wrongly; this one means the hub lost — or
 * never had — a copy of something whose content we still hold in full, and the
 * useful response is to put it back rather than to show the customer an error
 * about an id they have never seen and cannot act on.
 */
class AiHubObjectMissingException extends Exception
{
    /**
     * If it reaches a request unrepaired, answer in words about the thing the
     * customer was editing — never "the hub no longer has the object needed to
     * update the agent", which is a sentence about our mirror and their id.
     */
    public function render(\Illuminate\Http\Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => 'Este item de IA não está mais disponível no serviço. Recarregue a página e tente novamente.',
            'code' => 'ai_object_missing',
        ], 422);
    }
}
