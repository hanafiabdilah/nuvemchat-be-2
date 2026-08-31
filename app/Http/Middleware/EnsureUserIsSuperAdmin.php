<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * Ensure the authenticated account is a platform Back Office admin.
     *
     * The type is now the first half of the check. It used to be "a user with
     * no tenant", which was a property any row could drift into; an admin is
     * its own model, so a customer's token cannot reach this surface no matter
     * what its columns say. The role check stays because an admin stripped of
     * every platform role should lose access without being deleted.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Back Office access only.',
            ], 403);
        }

        return $next($request);
    }
}
