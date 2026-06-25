<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * Ensure the authenticated user is a platform Back Office admin.
     *
     * A Back Office admin is a user that holds the `super-admin` role and is
     * NOT scoped to any tenant (tenant_id === null).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! is_null($user->tenant_id) || ! $user->hasRole('super-admin')) {
            return response()->json([
                'message' => 'Forbidden. Back Office access only.',
            ], 403);
        }

        return $next($request);
    }
}
