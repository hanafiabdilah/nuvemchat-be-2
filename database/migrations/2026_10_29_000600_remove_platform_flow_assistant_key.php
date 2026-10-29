<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Forget the platform's flow-assistant OpenAI key.
 *
 * The assistant now runs on each workspace's own key, or on one it rents (see
 * the flow_assistant_settings migration). Nothing reads these rows any more,
 * and one of them is a provider secret — keeping it after the last reader is
 * gone is only a liability.
 *
 * The hub ids are logged before the rows go: the credential and agent they name
 * still exist at the hub, and this is the last place anyone can read which
 * ones to delete there. Revoking the key at OpenAI is what actually matters —
 * the hub holds a copy of it.
 */
return new class extends Migration
{
    private const KEYS = [
        'flow_assistant.enabled',
        'flow_assistant.model',
        'flow_assistant.openai_api_key',
        'flow_assistant.hub_credential_id',
        'flow_assistant.hub_agent_id',
        'flow_assistant.agent_external_id',
        'flow_assistant.prompt_hash',
    ];

    public function up(): void
    {
        $leftovers = [];

        foreach (['flow_assistant.hub_credential_id', 'flow_assistant.hub_agent_id'] as $key) {
            try {
                $leftovers[$key] = Setting::query()->where('key', $key)->first()?->value;
            } catch (\Throwable) {
                // Undecryptable under the current APP_KEY: nothing to report.
            }
        }

        if (array_filter($leftovers) !== []) {
            Log::warning('Flow assistant: platform key removed; delete these leftovers at the AI hub', $leftovers);
        }

        Setting::query()->whereIn('key', self::KEYS)->delete();

        foreach (self::KEYS as $key) {
            Cache::forget("setting:{$key}");
        }
    }

    public function down(): void
    {
        // The key is gone for good; there is nothing to restore.
    }
};
