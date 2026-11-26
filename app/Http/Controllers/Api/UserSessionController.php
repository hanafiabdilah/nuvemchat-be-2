<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\SingleSession;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where this account is signed in (Profile → Signed-in devices).
 *
 * Always the caller's own sessions, so no permission: knowing which devices
 * hold your account is part of keeping it.
 */
class UserSessionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken && $current->exists ? (int) $current->id : null;

        $sessions = SingleSession::activeSessions($user)
            ->map(fn (PersonalAccessToken $token) => [
                'id' => (string) $token->id,
                // null = signed in before kinds were kept: the device is not known.
                'kind' => $token->getAttribute('device_kind'),
                'device' => $token->getAttribute('device_label'),
                'signed_in_at' => $token->created_at?->toIso8601String(),
                'last_active_at' => ($token->last_used_at ?? $token->created_at)?->toIso8601String(),
                'current' => (int) $token->id === $currentId,
            ])
            // This device first: it is the one the reader is holding.
            ->sortByDesc('current')
            ->values();

        return response()->json([
            'data' => $sessions,
            // Off = no limit is applied, and the page must not claim one.
            'one_per_kind' => SingleSession::enabled(),
        ]);
    }
}
