<?php

namespace App\Services\AiAgentHub;

use Illuminate\Support\Facades\Log;

/**
 * The `inputAudio` block: who listens to the customer's voice note, and how.
 *
 * Two providers, one purpose, and almost no shared field names — OpenAI takes
 * `transcriptionModel` plus a sentence of `prompt`, ElevenLabs takes `model`
 * plus a list of `keyterms`. Keeping both shapes here means the rest of the
 * app asks for "transcription" and never for a provider's spelling of it.
 *
 * The choice belongs to the flow node, because it is a cost and quality
 * decision per workspace, not a property of the platform. A node that says
 * nothing gets the platform default (config/ai.php).
 */
class AiTranscription
{
    public const OPENAI = 'openai';
    public const ELEVENLABS = 'elevenlabs';

    /**
     * The block to send with this run, or [] when there is no audio in it —
     * asking the hub to transcribe a run with nothing to transcribe is a field
     * it would have to reject.
     *
     * @param  array<string, mixed>|null  $nodeData  the AIAgent node's data
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<string, mixed>
     */
    public static function options(?array $nodeData, array $attachments): array
    {
        if (! AiAttachments::audioEnabled() || ! self::carriesAudio($attachments)) {
            return [];
        }

        $config = self::config($nodeData);

        $common = array_filter([
            'provider' => strtoupper($config['provider']),
            'providerCredentialId' => $config['credential_id'],
            'language' => config('ai.audio.language'),
        ], fn ($value) => $value !== null && $value !== '');

        if ($config['provider'] === self::ELEVENLABS) {
            return array_filter(array_merge($common, [
                'model' => $config['model'] ?? config('ai.audio.elevenlabs_model'),
                'keyterms' => config('ai.audio.keyterms') ?: null,
                // Neither is wanted here: the transcript is read by a person
                // skimming a thread, and word-level timestamps and "[laughs]"
                // annotations are noise in that line.
                'timestampsGranularity' => 'none',
                'tagAudioEvents' => false,
            ]), fn ($value) => $value !== null && $value !== '');
        }

        return array_filter(array_merge($common, [
            'transcriptionModel' => $config['model'] ?? config('ai.audio.transcription_model'),
            'prompt' => config('ai.audio.prompt'),
        ]), fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The node's transcription settings, filled in from the platform default.
     *
     * @param  array<string, mixed>|null  $nodeData
     * @return array{provider: string, credential_id: ?string, model: ?string}
     */
    public static function config(?array $nodeData): array
    {
        $raw = is_array($nodeData['input_audio'] ?? null) ? $nodeData['input_audio'] : [];

        return [
            'provider' => self::provider($raw['provider'] ?? null, (string) config('ai.audio.provider', self::OPENAI)),
            'credential_id' => self::credentialId($raw['credential_id'] ?? null),
            'model' => self::text($raw['model'] ?? null),
        ];
    }

    /**
     * A hub credential id, or null when what was entered is plainly not one.
     *
     * The hub stores a provider's API key and hands back an id; the id is what
     * belongs here. Pasting the key itself is the obvious mistake to make —
     * it is the thing the person is holding — and the hub answers it with
     * "Audio speech credential not found or disabled", which reads like the
     * credential is broken rather than like the wrong thing was pasted.
     *
     * Dropping it is better than forwarding it: with no id the hub falls back
     * to the account's default credential for that provider, which is what
     * most workspaces have anyway. And a secret must not travel in a field
     * that is neither encrypted nor meant to hold one.
     */
    public static function credentialId(mixed $value): ?string
    {
        $id = self::text($value);

        if ($id === null) {
            return null;
        }

        // OpenAI keys start `sk-`, ElevenLabs `sk_`; hub ids are ~25-character
        // cuids, so anything much longer is a secret rather than an id.
        if (preg_match('/^sk[-_]/i', $id) || strlen($id) > 40) {
            Log::warning('AiTranscription: an API key was entered where a hub credential id belongs, ignoring it', [
                'length' => strlen($id),
                'prefix' => substr($id, 0, 3),
            ]);

            return null;
        }

        return $id;
    }

    /** True when this run is carrying something to listen to. */
    public static function carriesAudio(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? null) === 'audio') {
                return true;
            }
        }

        return false;
    }

    /** A known provider name, or the fallback. Unknown values are never forwarded. */
    public static function provider(mixed $value, string $fallback = self::OPENAI): string
    {
        $provider = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($provider, [self::OPENAI, self::ELEVENLABS], true) ? $provider : $fallback;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
