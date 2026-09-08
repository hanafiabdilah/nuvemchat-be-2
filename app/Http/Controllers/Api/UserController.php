<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Connection;
use App\Services\Billing\SubscriptionGate;
use App\Services\User\AvatarStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index (Request $request) {
        $user = $request->user();
        $user->load(['roles', 'permissions']);
        $tenant = $user->tenant;
        $data = $user->toResource(UserResource::class)->resolve($request);

        if ($tenant !== null) {
            $gate = app(SubscriptionGate::class);
            $data['entitlements'] = $gate->entitlements($tenant);

            // Mirrors EnsureSubscriptionActive so the UI can hide what the API would
            // 403 on. Entitlements alone are status-blind (they come from the plan
            // snapshot), so they cannot answer "is this tenant paid up?".
            $data['billing'] = [
                'enforced' => (bool) config('services.billing.enforce'),
                'subscription_usable' => $gate->usable($tenant),
            ];
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => 'required_with:password|string',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        // Verify current password if password is being changed
        if (!empty($validated['password'])) {
            if (!Hash::check($validated['current_password'] ?? '', $user->password)) {
                return response()->json([
                    'message' => 'Current password is incorrect',
                    'errors' => [
                        'current_password' => ['The current password is incorrect.']
                    ]
                ], 422);
            }
        }

        // Update name and email
        $user->name = $validated['name'];
        $user->email = $validated['email'];

        // Update password only if provided
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->toResource(UserResource::class),
        ]);
    }

    /**
     * Your own photo.
     *
     * Ungated on purpose — no permission, no owner check. Every other write to
     * an account is somebody acting on somebody else and needs a rule for it;
     * this one is a person changing their own picture, and requiring a grant
     * for that would mean an agent whose role omitted it could never fix a
     * photo their manager uploaded of them.
     *
     * Returns just the link rather than the whole profile: the caller has the
     * rest of it already, and this is the only field that moved.
     */
    public function updateAvatar(Request $request, AvatarStorage $avatars)
    {
        $request->validate(['avatar' => AvatarStorage::rules()]);

        $user = $avatars->store($request->user(), $request->file('avatar'));

        return response()->json([
            'message' => 'Photo updated successfully',
            'avatar' => $user->avatar_url,
        ]);
    }

    public function destroyAvatar(Request $request, AvatarStorage $avatars)
    {
        $avatars->clear($request->user());

        return response()->json([
            'message' => 'Photo removed successfully',
            'avatar' => null,
        ]);
    }

    /**
     * Per-user UI preferences: which theme preset the app renders and whether it
     * follows light/dark/system. Cosmetic only — kept apart from update() so the
     * frontend can persist a single click without resending the whole profile.
     */
    public function updatePreferences(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            // Unknown/retired theme ids are rejected here rather than stored and
            // silently ignored by the frontend fallback.
            'theme' => ['sometimes', 'string', Rule::in(['classic', 'studio'])],
            'appearance' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        $user->ui_preferences = array_merge($user->ui_preferences ?? [], $validated);
        $user->save();

        return response()->json([
            'ui_preferences' => $user->ui_preferences,
        ]);
    }

    /**
     * Per-user notification settings: whether an incoming message is allowed to
     * raise a toast and play a sound, and which connections are exempted from
     * that individually.
     *
     * Partial like updatePreferences() — the settings page saves one switch at
     * a time, and future switches must not be wiped by a client that predates
     * them.
     */
    public function updateNotificationPreferences(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'incoming_messages' => ['sometimes', 'boolean'],
            'muted_connection_ids' => ['sometimes', 'array'],
            'muted_connection_ids.*' => ['integer'],
        ]);

        if (array_key_exists('muted_connection_ids', $validated)) {
            // Narrowed to the tenant's own connections rather than rejected:
            // an id that no longer exists (connection deleted while the page was
            // open) cannot represent an intention worth keeping, and failing the
            // whole save over it would lose the switch the user actually flipped.
            $ownIds = Connection::where('tenant_id', $user->tenant_id)
                ->whereIn('id', $validated['muted_connection_ids'])
                ->pluck('id')
                ->all();

            $validated['muted_connection_ids'] = array_values(array_map('intval', $ownIds));
        }

        $user->notification_preferences = array_merge($user->notification_preferences ?? [], $validated);
        $user->save();

        return response()->json([
            'notification_preferences' => $user->notificationSettings(),
        ]);
    }

    /**
     * "This dashboard is still open and someone is behind it."
     *
     * The SPA calls this once a minute. Nothing else in the product can answer
     * the question: a Sanctum token proves someone signed in at some point, and
     * an Echo subscription is a fact about Reverb that no request handler can
     * read. Automatic routing needs the answer — see LastAgentRouter, which
     * would otherwise hand a returning customer to an empty chair.
     *
     * Deliberately writes nothing back. The caller has no use for a body, and
     * an empty response keeps the once-a-minute cost of the ping to a single
     * UPDATE.
     */
    public function heartbeat(Request $request)
    {
        $request->user()->markSeen();

        return response()->noContent();
    }
}
