<?php

namespace App\Services\AiAgentHub;

use App\Enums\Connection\Channel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * When an AI agent answers out loud, and in what voice.
 *
 * The decision has to be made *before* the run: `responseAudio` is a field of
 * the request, so by the time the hub has written the reply it is too late to
 * ask for it spoken. That single constraint shapes everything here — the only
 * evidence available is what the customer just sent.
 *
 * Two things count as evidence, and they behave differently on purpose:
 *
 *   they sent a voice note  — mirrored, turn by turn. Someone who records one
 *                             message and then types is not asking to be
 *                             spoken to forever; answering in the medium they
 *                             just used is simply matching them.
 *
 *   they asked for audio    — sticky for the rest of the conversation. "me
 *                             responde por áudio" is an instruction, not a
 *                             property of that one message, and making them
 *                             repeat it every turn would be answering the
 *                             words while ignoring the request.
 */
class AiVoiceReply
{
    /** Speak when the customer speaks, or when they have asked us to. */
    public const MODE_MATCH_CUSTOMER = 'match_customer';

    /** Speak every turn, whatever the customer does. */
    public const MODE_ALWAYS = 'always';

    /**
     * File extension per hub format. Opus comes back in an Ogg container, and
     * `.ogg` is what both WhatsApp handlers already recognise as audio they do
     * not need to re-encode.
     */
    private const FORMAT_EXTENSIONS = [
        'mp3' => 'mp3',
        'opus' => 'ogg',
        'aac' => 'aac',
        'flac' => 'flac',
        'wav' => 'wav',
        'pcm' => 'wav',
    ];

    /** The voice note is the answer. */
    public const DELIVERY_AUDIO_ONLY = 'audio_only';

    /** The written answer first, the voice note after it. */
    public const DELIVERY_AUDIO_AND_TEXT = 'audio_and_text';

    /**
     * Explicit refusals, checked before anything else.
     *
     * A customer who says "prefiro texto" has to be able to stop the voice
     * notes with one sentence; if that took two tries the feature would be
     * something done *to* them.
     */
    private const STOP_PATTERNS = [
        '/\b(prefiro|quero|manda|mande|envia|envie|responde|responda|escreve|escreva)\b[^,.;!?]{0,24}\b(texto|escrito|escrita)\b/',
        '/\bpor escrito\b/',
        '/\b(texto|escrito) por favor\b/',
        '/\b(sem|chega de|par(?:a|e|ar|em) de|nao|nem)\b[^,.;!?]{0,24}\baudios?\b/',
        '/\bnao\b[^,.;!?]{0,24}\b(ouvir|escutar|audios?)\b/',
        '/\b(prefer|send|reply|answer|write)\b[^,.;!?]{0,24}\b(text|writing)\b/',
        '/\b(no|stop|without)\b[^,.;!?]{0,24}\b(audio|voice)\b/',
    ];

    /**
     * Asking to be answered out loud. Deliberately verb-led: a bare mention of
     * the word "áudio" is usually the customer talking *about* the voice note
     * they just sent, not asking for one back.
     */
    private const VOICE_PATTERNS = [
        '/\b(manda|mande|mandar|envia|envie|enviar|grava|grave|gravar)\b[^,.;!?]{0,24}\baudios?\b/',
        '/\b(responde|responda|responder|explica|explique|explicar|fala|fale|falar)\b[^,.;!?]{0,24}\b(em|por|com|de)\s+audios?\b/',
        '/\b(responde|responda|responder)\b[^,.;!?]{0,16}\bfalando\b/',
        '/\b(quero|prefiro|pode ser|manda tudo)\b[^,.;!?]{0,16}\bem\s+audios?\b/',
        '/\b(quero|prefiro)\b[^,.;!?]{0,16}\baudios?\b/',
        '/\baudios? por favor\b/',
        '/\bpode (me )?(falar|responder falando)\b/',
        '/\b(send|reply|answer|record)\b[^,.;!?]{0,24}\b(an? )?(audio|voice note|voice message)\b/',
        '/\b(in|with|by) (audio|voice)\b/',
    ];

    /**
     * The node's settings, filled in. A node built before this feature existed
     * has no `response_audio` key at all, and reads as silent — voice is never
     * something a flow starts doing on its own.
     *
     * @param  array<string, mixed>|null  $nodeData
     * @return array{enabled: bool, mode: string, delivery: string, voice: ?string, speed: ?float, instructions: ?string}
     */
    public static function config(?array $nodeData): array
    {
        $raw = is_array($nodeData['response_audio'] ?? null) ? $nodeData['response_audio'] : [];

        $mode = $raw['mode'] ?? self::MODE_MATCH_CUSTOMER;
        $delivery = $raw['delivery'] ?? self::DELIVERY_AUDIO_ONLY;

        $provider = AiTranscription::provider(
            $raw['provider'] ?? null,
            (string) config('ai.voice.provider', AiTranscription::OPENAI),
        );

        $voiceId = self::text($raw['voice_id'] ?? null) ?? self::text(config('ai.voice.elevenlabs_voice_id'));

        // A voice on ElevenLabs is an id from somebody's own account, so there
        // is nothing to fall back to: without one the node speaks with OpenAI
        // rather than asking the hub to use a voice that does not exist.
        $effective = ($provider === AiTranscription::ELEVENLABS && $voiceId === null)
            ? AiTranscription::OPENAI
            : $provider;

        // Everything below the provider line was entered for one of them and
        // means nothing to the other: an ElevenLabs model handed to OpenAI is
        // not a model, and its credential is not a credential. When the
        // fallback above changes who speaks, those fields do not come along.
        $ownFields = $effective === $provider;

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false) && (bool) config('ai.voice.enabled', true),
            'mode' => in_array($mode, [self::MODE_MATCH_CUSTOMER, self::MODE_ALWAYS], true)
                ? $mode
                : self::MODE_MATCH_CUSTOMER,
            'delivery' => in_array($delivery, [self::DELIVERY_AUDIO_ONLY, self::DELIVERY_AUDIO_AND_TEXT], true)
                ? $delivery
                : self::DELIVERY_AUDIO_ONLY,
            // Falling back still delivers audio, which is what the author
            // asked for; silently answering in writing would be worse.
            'provider' => $effective,
            'credential_id' => $ownFields ? AiTranscription::credentialId($raw['credential_id'] ?? null) : null,
            'model' => $ownFields ? self::text($raw['model'] ?? null) : null,
            'voice' => self::text($raw['voice'] ?? null),
            'voice_id' => $effective === AiTranscription::ELEVENLABS ? $voiceId : null,
            'voice_settings' => $ownFields ? self::voiceSettings($raw['voice_settings'] ?? null) : [],
            'speed' => isset($raw['speed']) && is_numeric($raw['speed'])
                ? max(0.25, min(4.0, (float) $raw['speed']))
                : null,
            'instructions' => self::text($raw['instructions'] ?? null),
        ];
    }

    /**
     * ElevenLabs' four dials, kept only where the author actually moved one.
     *
     * Sent empty they are not "defaults of ours" — they are the provider's,
     * which is a better place for them to live than in a form nobody revisits.
     *
     * @return array<string, mixed>
     */
    private static function voiceSettings(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clamp = fn ($value) => is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : null;

        return array_filter([
            'stability' => $clamp($raw['stability'] ?? null),
            'similarityBoost' => $clamp($raw['similarity_boost'] ?? null),
            'style' => $clamp($raw['style'] ?? null),
            'useSpeakerBoost' => isset($raw['use_speaker_boost']) ? (bool) $raw['use_speaker_boost'] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Whether this turn should be spoken.
     *
     * @param  array<string, mixed>  $config  from config()
     */
    public static function shouldSpeak(array $config, Channel $channel, bool $customerSpoke, bool $voiceRequested): bool
    {
        if (! $config['enabled'] || ! $channel->supportsVoiceReply()) {
            return false;
        }

        return $config['mode'] === self::MODE_ALWAYS
            || $customerSpoke
            || $voiceRequested;
    }

    /**
     * The `responseAudio` block for the run.
     *
     * The node only overrides what its author actually chose; everything else
     * falls through to config/ai.php, so the product can be re-voiced in one
     * place instead of flow by flow.
     *
     * The format is the channel's call rather than the author's: it decides
     * whether the reply arrives as a voice note or as a file, which is not a
     * question anybody should have to answer per flow.
     *
     * @param  array<string, mixed>  $config  from config()
     * @return array<string, mixed>
     */
    public static function options(array $config, Channel $channel): array
    {
        $common = array_filter([
            'enabled' => true,
            'provider' => strtoupper($config['provider']),
            'providerCredentialId' => $config['credential_id'],
            'speed' => $config['speed'] ?? config('ai.voice.speed'),
        ], fn ($value) => $value !== null && $value !== '');

        if ($config['provider'] === AiTranscription::ELEVENLABS) {
            return array_filter(array_merge($common, [
                'model' => $config['model'] ?? config('ai.voice.elevenlabs_model'),
                'voiceId' => $config['voice_id'],
                'outputFormat' => self::elevenLabsOutputFormat($channel),
                'voiceSettings' => $config['voice_settings'] ?: null,
            ]), fn ($value) => $value !== null && $value !== '');
        }

        return array_filter(array_merge($common, [
            'model' => $config['model'] ?? config('ai.voice.model'),
            'voice' => $config['voice'] ?? config('ai.voice.voice'),
            'format' => self::format($channel),
            'instructions' => $config['instructions'] ?? config('ai.voice.instructions'),
        ]), fn ($value) => $value !== null && $value !== '');
    }

    /** What the hub is asked for: the platform override, or the channel's own answer. */
    public static function format(Channel $channel): string
    {
        $forced = self::text(config('ai.voice.format'));

        return $forced ?? $channel->voiceReplyFormat();
    }

    /**
     * The same choice in ElevenLabs' vocabulary, where a format also carries a
     * sample rate and a bitrate. 32 kbps Opus is what a voice note is: mono
     * speech, sized for a phone on mobile data.
     */
    public static function elevenLabsOutputFormat(Channel $channel): string
    {
        return self::format($channel) === 'opus' ? 'opus_48000_32' : 'mp3_44100_128';
    }

    /**
     * Read a customer message as an instruction about the medium.
     *
     * true = answer out loud from now on, false = go back to writing,
     * null = they said nothing about it, which is almost every message.
     */
    public static function requestSignal(?string $text): ?bool
    {
        $normalized = self::normalize((string) $text);

        if ($normalized === '') {
            return null;
        }

        foreach (self::STOP_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return false;
            }
        }

        foreach (self::VOICE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return null;
    }

    /**
     * Fetch the generated file so it can be sent as a message of ours.
     *
     * Deliberately not handed to the channel as a link: the hub's URL carries
     * an `expiresAt`, and `media:purge` leaves absolute URLs alone — a bubble
     * pointing at it would work today and be a dead player next month. The
     * bytes come here once, and the send path stores its own copy.
     */
    public static function download(string $url, string $format = 'mp3', ?string $mimeType = null): ?UploadedFile
    {
        $extension = self::extensionFor($format, $mimeType);

        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable $th) {
            Log::warning('AiVoiceReply: could not fetch the generated audio', [
                'error' => $th->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AiVoiceReply: the generated audio was not served', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'ai_voice_');

        if ($path === false || file_put_contents($path, $response->body()) === false) {
            return null;
        }

        return new UploadedFile(
            $path,
            'resposta-' . Str::lower(Str::random(6)) . '.' . $extension,
            $response->header('Content-Type') ?: 'audio/mpeg',
            null,
            true, // already "uploaded": skip the is_uploaded_file() check
        );
    }

    /**
     * The file extension to store the generated audio under.
     *
     * The name matters more than it looks. `.opus` sends the API Way handler
     * down its FFmpeg conversion branch (it re-encodes anything that is not
     * mp3/ogg), and WhatsApp Official reads the stored file's type to decide
     * whether the message is a voice note — so an Ogg container has to be
     * called `.ogg` whichever provider produced it.
     *
     * The MIME the hub reports wins, because it describes the bytes that
     * actually arrived. The format name is the fallback, and it comes in two
     * dialects: `opus` from OpenAI, `opus_48000_32` from ElevenLabs.
     */
    public static function extensionFor(string $format, ?string $mimeType = null): string
    {
        $mime = strtolower(trim(explode(';', (string) $mimeType)[0]));

        $fromMime = match ($mime) {
            'audio/ogg', 'audio/opus', 'application/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/aac' => 'aac',
            'audio/flac', 'audio/x-flac' => 'flac',
            default => null,
        };

        if ($fromMime !== null) {
            return $fromMime;
        }

        $family = strtolower(explode('_', trim($format))[0]);

        return self::FORMAT_EXTENSIONS[$family] ?? 'mp3';
    }

    /** Lowercase, unaccented, single-spaced — what the patterns are written against. */
    private static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii(trim($text)));

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
