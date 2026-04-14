<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuickMessageRequest;
use App\Http\Requests\UpdateQuickMessageRequest;
use App\Http\Resources\QuickMessageResource;
use App\Models\QuickMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickMessageController extends Controller
{
    /**
     * Display a listing of quick messages available for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $quickMessages = QuickMessage::availableForUser($user->id, $user->tenant_id)
            ->orderBy('shortcut', 'ASC')
            ->get();

        return response()->json([
            'data' => $quickMessages->toResourceCollection(QuickMessageResource::class),
        ]);
    }

    /**
     * Store a newly created quick message.
     */
    public function store(StoreQuickMessageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $quickMessage = QuickMessage::create($validated);

        return response()->json([
            'message' => 'Quick message created successfully',
            'data' => $quickMessage->toResource(QuickMessageResource::class),
        ], 201);
    }

    /**
     * Update the specified quick message.
     */
    public function update(UpdateQuickMessageRequest $request, QuickMessage $quickMessage): JsonResponse
    {
        // Ensure quick message belongs to user's tenant
        if ($quickMessage->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validated();

        $quickMessage->update($validated);

        return response()->json([
            'message' => 'Quick message updated successfully',
            'data' => $quickMessage->fresh()->toResource(QuickMessageResource::class),
        ]);
    }

    /**
     * Remove the specified quick message.
     */
    public function destroy(QuickMessage $quickMessage, Request $request): JsonResponse
    {
        // Ensure quick message belongs to user's tenant
        if ($quickMessage->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Unauthorized');
        }

        // Check authorization
        if ($quickMessage->isTenantLevel() && !$request->user()->hasRole('owner')) {
            abort(403, 'Unauthorized to delete tenant-level quick message');
        }

        if ($quickMessage->isUserSpecific() && $quickMessage->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized to delete this quick message');
        }

        $quickMessage->delete();

        return response()->json([
            'message' => 'Quick message deleted successfully',
        ]);
    }
}
