<?php

namespace App\Http\Controllers\Api\Push;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Push\FirebaseConfig;
use App\Services\Push\PushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The mobile app's phones, registered for push notifications by the dashboard
 * running inside the app's WebView (it receives the FCM token from Flutter over
 * the JS bridge — see docs/mobile-push.md). Always the caller's own devices.
 */
class DeviceController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => DeviceToken::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('last_registered_at')
                ->get()
                ->map(fn (DeviceToken $device) => $this->present($device, $request)),
            'push_configured' => FirebaseConfig::isConfigured(),
        ]);
    }

    /**
     * Register or refresh this phone. Idempotent: the app calls it on every
     * launch and on every token refresh.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'device_id' => ['required', 'string', 'max:191'],
            'platform' => ['required', 'string', Rule::in(DeviceToken::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('markets.locales', [])))],
        ]);

        $user = $request->user();
        $sessionId = $this->sessionId($request);

        $device = DB::transaction(function () use ($validated, $user, $sessionId) {
            // A token belongs to one installation. When it shows up under a
            // new device id (app reinstalled), the old row goes — otherwise
            // the same phone is notified twice.
            DeviceToken::query()
                ->where('token', $validated['token'])
                ->where('device_id', '!=', $validated['device_id'])
                ->delete();

            // Keyed on the installation, not the user: the phone that logs out
            // and logs in as somebody else must move to that somebody, or the
            // first person keeps receiving the second person's customers.
            return DeviceToken::query()->updateOrCreate(
                ['device_id' => $validated['device_id']],
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'personal_access_token_id' => $sessionId,
                    'token' => $validated['token'],
                    'platform' => $validated['platform'],
                    'app_version' => $validated['app_version'] ?? null,
                    'locale' => $validated['locale'] ?? null,
                    'last_registered_at' => now(),
                ],
            );
        });

        return response()->json(['data' => $this->present($device, $request)], 201);
    }

    /** Stop pushing to this phone (the app's own "turn off notifications"). */
    public function destroy(Request $request, string $deviceId)
    {
        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('device_id', $deviceId)
            ->delete();

        return response()->noContent();
    }

    /** A test notification to the caller's phones, answered synchronously. */
    public function test(Request $request, PushNotifier $notifier)
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        if (! FirebaseConfig::isConfigured()) {
            return response()->json([
                'message' => 'Push notifications are not set up on this platform yet.',
                'code' => 'push_not_configured',
            ], 503);
        }

        $devices = DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->when($validated['device_id'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->get();

        if ($devices->isEmpty()) {
            return response()->json([
                'message' => 'No device is registered for this account.',
                'code' => 'no_devices',
            ], 404);
        }

        return response()->json(['data' => $notifier->sendTest($devices)]);
    }

    /** The stored token behind this request, when it is one (not a cookie session). */
    private function sessionId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? (int) $token->id : null;
    }

    /** @return array<string, mixed> */
    private function present(DeviceToken $device, Request $request): array
    {
        $sessionId = $this->sessionId($request);

        return [
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'locale' => $device->locale,
            'current_session' => $sessionId !== null && (int) $device->personal_access_token_id === $sessionId,
            'last_registered_at' => $device->last_registered_at?->toIso8601String(),
            'last_sent_at' => $device->last_sent_at?->toIso8601String(),
            'last_error' => $device->last_error,
        ];
    }
}
