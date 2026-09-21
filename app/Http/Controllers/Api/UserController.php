<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketResource;
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
            // The country this workspace sells in. Fixed for good, so money can
            // be formatted and defaults chosen without asking again.
            $data['market'] = MarketResource::make($tenant->market)->resolve($request);

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

        // ⚠️ Changing the address the account is reachable at is a change of
        // ownership, so it costs the password too. It used to be free: anyone
        // holding a session — a stolen token, a laptop left open — could point
        // the account at their own address and keep it.
        $changingEmail = mb_strtolower(trim($validated['email'])) !== mb_strtolower((string) $user->email);

        if ($changingEmail || ! empty($validated['password'])) {
            if (! Hash::check($validated['current_password'] ?? '', $user->password)) {
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

        // ⚠️ Every other session this account had is now somebody else's,
        // whether that somebody is an attacker or a shared machine. A password
        // change that does not end them is a control that only looks like one —
        // the victim believes they have locked the door.
        //
        // The same applies to an e-mail change: if it was not the owner who
        // made it, the owner needs whatever sessions the other party holds to
        // stop working.
        if ($changingEmail || ! empty($validated['password'])) {
            $current = $request->user()?->currentAccessToken();

            $user->tokens()
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->delete();
        }

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
     * Per-user display preferences: theme preset, light/dark, and language.
     *
     * Kept apart from update() so the frontend can persist a single click
     * without resending the whole profile — the settings page saves one control
     * at a time, and each of these is one control.
     *
     * Language rides along here because it is chosen on the same screen and by
     * the same click, but it is stored in its own column rather than in the
     * ui_preferences blob: that blob is cosmetic and the server never reads it,
     * whereas the language is the one preference a notification will have to
     * consult with nobody at the screen.
     */
    public function updatePreferences(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            // Unknown/retired theme ids are rejected here rather than stored and
            // silently ignored by the frontend fallback.
            'theme' => ['sometimes', 'string', Rule::in(['classic', 'studio'])],
            'appearance' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
            // The same list markets choose their default from, so a person can
            // never pick a language a market could not have been launched in.
            // Nullable is a real value: it means "follow my workspace's country
            // again", which is the only way back once something was chosen.
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('markets.locales')))],
        ]);

        if (array_key_exists('locale', $validated)) {
            $user->locale = $validated['locale'];
            unset($validated['locale']);
        }

        $user->ui_preferences = array_merge($user->ui_preferences ?? [], $validated);
        $user->save();

        return response()->json([
            'ui_preferences' => $user->ui_preferences,
            'locale' => $user->locale,
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
