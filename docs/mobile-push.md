# Mobile push (Firebase Cloud Messaging)

Push notifications to the Pingly Flutter app, which shows the tenant dashboard in
a WebView. Guide for the mobile team: `panduan-push-notification-flutter.md`
(monorepo root, Indonesian).

## How a phone gets registered

Flutter never authenticates. It hands the FCM token to the page over the JS
bridge (`window.PinglyApp.setPushToken`, `nuvemchat-fe-2/src/lib/nativeApp.ts`),
and the page registers it with its own session: `POST /api/user/devices`.

`device_tokens.personal_access_token_id` cascades: logout (`POST /api/auth/logout`,
new — the SPA used to only forget its token), a password/e-mail change (revokes
other tokens) and `AgentRemoval` all stop pushes to that phone without extra code.

## Who gets what

`App\Services\Push\PushNotifier` listens to the broadcast events
(`PushEvents::register()` in `AppServiceProvider`) and mirrors the dashboard's
toast rules, enforced server-side: connection access, muted thread,
Settings → Notifications. The customer's message is never in the payload.

## Setup

1. Back Office → Integrations → **Firebase** → upload the service account JSON
   (same Firebase project as the app). Press **Test connection**.
2. Nothing else: sends use the `default` queue (`PUSH_QUEUE` to move them),
   `PUSH_ENABLED=false` is the kill switch, `push:prune-devices` runs daily.

## Diagnosing

- BO → Health → *Mobile push (Firebase)*: amber when sends fail (last error shown).
- `device_tokens.last_error` / `last_sent_at` per phone.
- `SENDER_ID_MISMATCH` = service account from another Firebase project.
- Dead tokens (`UNREGISTERED`) are deleted automatically.

Tests: `tests/Feature/Push/MobilePushTest.php`.
