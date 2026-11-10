# WhatsApp calls answered in the dashboard (Cloud API Calling)

A customer calls a WhatsApp Official number; every agent who can answer is rung
in the dashboard (and on the phone app); the first to answer talks to the
customer **from the browser**. The server never carries audio: the answering
browser is the WebRTC endpoint and the backend only relays signalling between it
and Meta (`App\Services\Calls\CallService`).

## Turning it on for a number

1. Meta App dashboard → WhatsApp → Configuration → webhook fields: subscribe
   **`calls`** (one-time, platform-wide). Without it Meta refuses to enable
   calling (error 138018).
2. The number needs a messaging limit of **2,000 or more** (error 138015).
3. Connections → the number → **WhatsApp calls** → switch on. That calls
   `POST /{phone-number-id}/settings` with `calling.status = ENABLED`; the state
   shown is always read live from Meta.
4. Optional: the **message when nobody can answer** (sent in the chat, inside
   the 24 h window only).

Coexistence numbers cannot use this: their calls stay on the WhatsApp Business
app on the phone.

## Who is rung

Everyone at once who: has `calls.answer`, can reach the connection (and the
thread, if it is exclusive), has *Receive WhatsApp calls* on (Settings →
Notifications), is Online (presence switch), is not on another call, and either
has a dashboard that reported in recently or a phone with the app registered.

No cap per number (Meta allows 1,000 concurrent calls); the people are the
limit, one call each. Nobody available → the call is **rejected at once** and
the busy message is sent. Everyone rung declines → same. Rings out → missed +
busy message.

Answering = taking the conversation (Pending/AI → Active for the agent; another
agent's thread → taken over, with the usual note). Nothing is sent to the
customer.

## The answer sequence (browser)

`microphone → POST /calls/{id}/claim (offer SDP) → createAnswer + full ICE
gathering → POST /calls/{id}/answer (pre_accept) → wait for media up (≤6 s) →
POST /calls/{id}/accept → heartbeat every 10 s → POST /calls/{id}/hangup`.

The microphone is opened before claiming so a refused microphone never takes the
call away from colleagues. The local track stays disabled until accept returns
(Meta: no media before accept).

⚠️ `sdp` is excluded from `TrimStrings` (bootstrap/app.php): an SDP must end in
CRLF.

## What everybody sees

`call-updated` (connection channel) drives: the inbox row ("Ana is on a call"),
the strip in the chat room with a timer, the Live board (`agents[].on_call`).
`call-incoming` (user channel) rings the agent. Cold load: `GET /calls/active`.

## Recording, transcript and summary

Per connection, off by default (Connections → the number → WhatsApp calls →
**Record calls**). When on:

- On accept the call gets `recording_status = recording` and the connection's
  **recording notice** is sent in the chat (inside the 24 h window only). The
  agent sees a "Recording · let the customer know" reminder on the call bar;
  colleagues see a red "Recording" mark on the thread.
- The answering browser mixes both voices (Web Audio) and uploads a piece every
  10 s: `POST /calls/{id}/recording` (`chunk`, `index`, `mime`), then
  `POST /calls/{id}/recording/complete` when the call ends. Pieces wait in
  `call-recordings/{call}/` on the media disk.
- `FinalizeCallRecording` (media queue) joins the pieces, re-encodes to mono
  AAC `.m4a` with ffmpeg (falls back to the raw WebM/MP4 if ffmpeg fails), and
  **attaches it to the call's note** (`media/calls/{call}/…`). That puts it
  under normal media retention: signed URLs, `media:purge` after 90 days, the
  "expired" state. A fallback finalize is queued 60 s after the call ends for a
  browser that closed before saying "complete".
- `TranscribeCallRecording` runs one hub run on the connection's **"Respond with
  AI" agent** (`connections.ai_suggest_agent_id`) with the file as an audio
  attachment + `inputAudio`: the transcript comes back in
  `output.inputAudio.items[0].text`, the summary is the model's reply. Its own
  hub conversation (`call-summary:{id}`), never retried (second charge),
  billed like any run (own key or balance). No agent / no AI plan / no balance /
  file over `CALLS_RECORDING_MAX_AI_BYTES` (24 MB) → recording kept, note says
  why there is no transcript.
- Transcript and summary live in the note's `meta.call.recording` (exposed as
  `meta.call_recording` by MessageResource) and outlive the audio.

Ops: needs ffmpeg in the image (already there) and the `queue-media` worker.

## Keeping it honest

`calls:sweep` (every minute, health check "WhatsApp call sweep") ends: ringing
calls past 90 s (lost webhook), claims stuck connecting past 60 s, and running
calls whose browser stopped heart-beating for 45 s or that passed 4 h.

Settings: `config/calls.php` — `CALLS_ENABLED` (kill switch: notes only, nobody
rung), `CALLS_STUN_URLS`, `CALLS_HEARTBEAT_SECONDS`, `CALLS_LOST_AFTER_SECONDS`,
`CALLS_PUSH_QUEUE` (defaults to the presence queue — the ring must beat Meta's
30–60 s timeout).

## Mobile app (Flutter) contract

The app is a WebView of the dashboard, so the call itself runs in the page
(WebRTC). What the app must do:

**Permissions / WebView**

- Android: `RECORD_AUDIO` + `MODIFY_AUDIO_SETTINGS`; grant the WebView's
  `onPermissionRequest` for the audio capture resource on our origin.
- iOS: `NSMicrophoneUsageDescription`; WKWebView with
  `allowsInlineMediaPlayback = true`,
  `mediaTypesRequiringUserActionForPlayback = []`, and grant
  `requestMediaCapturePermission` for our origin. Background mode **audio**
  (and **voip** if CallKit is used) so the call keeps running with the screen
  off.

**Push `incoming_call`** (FCM, data-only on Android, alert on iOS)

```
data: { type: "incoming_call", call_id, conversation_id, connection_id,
        contact_name, connection_name, expires_at (unix s),
        url: "/conversations?conversation={id}&call={call_id}" }
```

- App in the **foreground**: ignore it — the page rings itself over the socket.
- App in the background (Android): show a full-screen incoming-call UI (e.g.
  `flutter_callkit_incoming`) until `expires_at`. **Answer** → open the WebView
  at `url + "&answer=1"` (or call `window.PinglyApp.answerCall(call_id)` if the
  page is already loaded). **Decline** → `window.PinglyApp.declineCall(call_id)`
  once the page is up (only this agent stops ringing).
- iOS: an alert with sound arrives (no VoIP push yet — a true CallKit ring when
  the app is killed would need PushKit, which FCM cannot send). Tapping it opens
  `url`; the page shows the ring if the call is still ringing.

**Push `call_cancelled`**

```
data: { type: "call_cancelled", call_id, conversation_id, status, agent, url }
```

Dismiss the call UI for that `call_id` (someone else answered, or it ended). On
iOS it replaces the ring alert (same `apns-collapse-id`).

**Bridge (`PinglyNative`, page → app)**

- `{"type":"call_started","call_id":1,"conversation_id":2}` — keep the audio
  session active and the WebView running in the background.
- `{"type":"call_ended","call_id":1}` — release them.
- `{"type":"permission_denied","permission":"microphone"}` — as before (only
  after the app declared the `permissions` capability).

**Bridge (`window.PinglyApp`, app → page)** — version 2 adds
`answerCall(callId)` and `declineCall(callId)`.

## Tests

`tests/Feature/Calls/WhatsappCallingTest.php` (ring rules, refusal + busy
message, first-answer-wins, pre_accept → accept with the same SDP, take-over,
decline-all, terminate, hangup, sweep, heartbeat, visibility, Live board,
connection settings, Coexistence, kill switch).
