<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
use App\Services\Webhook\ChatWebhookSecret;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectionResource extends JsonResource
{
    /**
     * Credential keys that never leave the server, whatever channel wrote them.
     *
     * `token` is here for the channels that use it as a secret (API Way's
     * instance token authorizes the entire core API); Telegram and Discord get
     * it back under a permission check — see scrubCredentials().
     */
    private const SECRET_KEYS = [
        'access_token',
        'user_access_token',
        'refresh_token',
        'password',
        'app_secret',
        'client_secret',
        'secret',
        'api_key',
        'private_key',
        'token',
        ChatWebhookSecret::CREDENTIAL_KEY,
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credentials = $this->scrubCredentials($request);

        return [
            'id' => $this->id,
            // What the dashboard shows as the Connection ID and what the public
            // API takes as `connection_id` — never the numeric id above.
            'public_id' => $this->public_id,
            'channel' => $this->channel,
            'name' => $this->name,
            'color' => $this->color,
            'status' => $this->status,
            'credentials' => $credentials,
            // Only email has an inbox to poll; other channels are push/webhook.
            'sync' => $this->when($this->channel === Channel::Email, fn () => [
                'status' => $this->sync_status,
                'error' => $this->sync_error,
                'remaining' => $this->sync_remaining,
                'started_at' => $this->sync_started_at,
                'last_synced_at' => $this->last_synced_at,
                // Import horizon in days; null = the whole mailbox.
                'window_days' => $this->sync_window_days,
            ]),
            'ai_suggest_agent_id' => $this->ai_suggest_agent_id,
            'ai_suggest_agent' => $this->ai_suggest_agent_id ? [
                'id' => $this->aiSuggestAgent?->id,
                'name' => $this->aiSuggestAgent?->name,
            ] : null,
            'automated_messages' => [
                'accept_message' => $this->accept_message,
                'closing_message' => $this->closing_message,
            ],
            // Routing, not a message: a contact who comes back inside the
            // tolerance goes to the agent who last served them instead of
            // through the flow and the queue.
            'return_to_last_agent' => [
                'enabled' => (bool) $this->return_to_last_agent,
                'tolerance_minutes' => (int) $this->return_to_last_agent_minutes,
            ],
            'flow' => new FlowResource($this->flow),
            // 'webhook_url' => route('webhook.chat', $this->id),
            'created_at' => $this->created_at,
            // Only the list query selects this (see ConnectionController@index).
            // Emitted conditionally rather than as a plain null so the SPA can
            // tell "no traffic yet" from "this response never carried it" — a
            // rename or a status check would otherwise blank the column out.
            'last_activity_at' => $this->when(
                array_key_exists('last_activity_at', $this->getAttributes()),
                fn () => $this->last_activity_at
                    ? Carbon::parse($this->last_activity_at)->toIso8601String()
                    : null
            ),
        ];
    }

    /**
     * Channel credentials, with the secrets taken out.
     *
     * ⚠️ This payload goes to EVERY signed-in member of the workspace. The
     * connection list is what the inbox renders its channel rail and its
     * filters from, so it cannot be put behind a permission without taking the
     * inbox away from ordinary agents — which means the filtering has to happen
     * here, not on the route.
     *
     * It used to strip secrets per channel, and only for four of them. WhatsApp
     * Official and Instagram were not among them, so their `access_token` — a
     * credential that sends messages as the business, reads the whole WABA and
     * survives the agent leaving the company — was handed to anybody who could
     * open the dashboard.
     *
     * ⚠️ A deny-list by key name, applied to every channel, rather than four
     * per-channel blocks. The blocks were the shape that let two channels be
     * forgotten; a name like `access_token` means the same thing wherever it
     * appears, and a channel added next year is covered without anybody
     * remembering to come back here.
     */
    private function scrubCredentials(Request $request): mixed
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return $credentials;
        }

        $deny = self::SECRET_KEYS;

        // The Telegram and Discord bot token is the one exception, and not a
        // grudging one: the connect wizard shows it in the field it was typed
        // into, so reconnecting does not mean going to find it again. Gated on
        // the permission that governs connecting rather than removed outright,
        // so an agent who only answers messages no longer receives it — a bot
        // token is total control of the bot.
        if (in_array($this->channel, [Channel::Telegram, Channel::Discord], true)
            && $request->user()?->can('connections.connect')) {
            $deny = array_diff($deny, ['token']);
        }

        foreach ($deny as $key) {
            unset($credentials[$key]);
        }

        return $credentials;
    }
}
