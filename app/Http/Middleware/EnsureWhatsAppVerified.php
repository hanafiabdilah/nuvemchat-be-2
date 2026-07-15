<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a signed-in tenant user from the app until their WhatsApp number is verified
 * (the backend counterpart to the frontend /verify-otp gate — defense in depth so the
 * check can't be skipped by calling the API directly).
 *
 * Only gates accounts that actually have a number on file but haven't verified it, so
 * legacy accounts created before this feature (no number) are never blocked. The OTP
 * routes live in their own group and are not gated here; `api/user` stays open so the
 * client can still read its verification state.
 */
class EnsureWhatsAppVerified
{
    /** Exact route URIs (relative) that remain accessible while unverified. */
    private const EXEMPT_URIS = ['api/user'];

    public function handle(Request $request, Closure $next): Response
    {
        // Master switch for the enforcement rollout.
        if (! config('services.whatsapp.verify_enforce', true)) {
            return $next($request);
        }

        if (in_array($request->route()?->uri(), self::EXEMPT_URIS, true)) {
            return $next($request);
        }

        $user = $request->user();

        // Platform super-admins (no tenant) and legacy accounts without a number are
        // never gated; only a captured-but-unverified number triggers the block.
        if ($user === null || $user->tenant === null || empty($user->whatsapp_number)) {
            return $next($request);
        }

        if ($user->whatsapp_verified_at === null) {
            return response()->json([
                'message' => 'Please verify your WhatsApp number to continue.',
                'code' => 'whatsapp_unverified',
            ], 403);
        }

        return $next($request);
    }
}
