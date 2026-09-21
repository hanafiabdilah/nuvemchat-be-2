<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Back Office admins out until they have enrolled a second factor.
 *
 * Off by default (`services.admin.mfa_required`), and that default is
 * load-bearing: turning it on before the people who hold these accounts have
 * had a chance to enrol would lock every operator out of the platform at once,
 * including whoever would have to turn it back off.
 *
 * The rollout is the same shape as the chat-webhook secrets: deploy, let
 * everyone enrol, watch Back Office → Health report nobody left, then set
 * ADMIN_MFA_REQUIRED=true.
 *
 * ⚠️ The exemptions below are the routes somebody with no second factor still
 * has to reach — reading who they are, enrolling, and signing out. Without
 * them this middleware locks out precisely the accounts it is asking to enrol,
 * which is a door that only opens from the side nobody is on.
 */
class EnsureAdminTwoFactor
{
    /** Paths an admin without a second factor may still use. */
    private const ALLOWED = [
        'api/admin/auth/me',
        'api/admin/auth/logout',
        'api/admin/account/two-factor',
        'api/admin/account/two-factor/*',
    ];

    public function __construct(
        private readonly TwoFactor $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.admin.mfa_required', false)) {
            return $next($request);
        }

        if ($request->is(self::ALLOWED)) {
            return $next($request);
        }

        $admin = $request->user();

        if ($admin && $this->twoFactor->isEnabled($admin)) {
            return $next($request);
        }

        // A code the Back Office branches on: it has to send this person to the
        // enrolment screen rather than show them a generic refusal they cannot
        // act on.
        return response()->json([
            'message' => 'Two-factor authentication is required for Back Office accounts. Set it up to continue.',
            'code' => 'two_factor_required',
        ], 403);
    }
}
