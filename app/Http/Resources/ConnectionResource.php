<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credentials = $this->credentials;

        if ($this->channel === Channel::Email && is_array($credentials)) {
            unset($credentials['password']);
        }

        // The SPA only needs the account identity (username/display_name);
        // OAuth tokens stay server-side.
        if ($this->channel === Channel::TikTok && is_array($credentials)) {
            unset($credentials['access_token'], $credentials['refresh_token']);
        }

        // Messenger: the SPA only needs the Page identity and the pending page
        // list for the picker; page/user tokens stay server-side.
        if ($this->channel === Channel::Messenger && is_array($credentials)) {
            unset($credentials['access_token'], $credentials['user_access_token']);
        }

        // The instance API token authorizes the whole core /v1 surface — the
        // SPA never needs it (token reveal is a dedicated, audited endpoint).
        if ($this->channel === Channel::WhatsappApiway && is_array($credentials)) {
            unset($credentials['token']);
        }

        return [
            'id' => $this->id,
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
            'api_key' => $this->api_key,
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
}
