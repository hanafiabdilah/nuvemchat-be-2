<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Models\Message;
use App\Services\Media\MediaRetention;
use Illuminate\Support\Collection;

/**
 * Turns the media a customer sent — screenshots and voice notes — into the
 * `message.attachments` array the hub's POST /v1/runs accepts.
 *
 * The hub fetches the URL itself, so what travels is the same signed link the
 * dashboard renders — no bytes are re-read and nothing is base64-encoded. That
 * link is public-but-signed and stays valid until the file's purge date
 * (App\Services\Media\MediaRetention), which is exactly as long as the run
 * could ever need it.
 */
class AiAttachments
{
    /**
     * The hub defines `type: image`, so an attachment is worth building only
     * when the bytes behind it are one. Extension is the honest test: it is
     * derived from the channel's declared MIME type at download time, and a
     * screenshot is just as often sent as a "document" as it is as an image.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Message types that can carry an image. Stickers are deliberately absent:
     * they are decoration, they are frequently animated WebP that vision
     * models reject, and paying for a picture of a cartoon thumbs-up on every
     * turn is worse than not seeing it.
     */
    private const IMAGE_CARRIERS = [MessageType::Image, MessageType::Document];

    /**
     * Audio containers the hub's transcription models accept, and the MIME the
     * payload declares for each.
     *
     * Narrower than the set of things a channel can deliver, and narrowly on
     * purpose. `amr`, `aac` and `opus` are all rejected by the transcription
     * API on the extension alone, so attaching them would buy a failed run,
     * the text-only retry, and the same "[audio]" the customer would have got
     * for free. WhatsApp voice notes — the overwhelming majority of what
     * arrives here — normalise to `ogg` on both variants.
     */
    private const AUDIO_MIME = [
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'mpga' => 'audio/mpeg',
        'mpeg' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'mp4' => 'audio/mp4',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
        'flac' => 'audio/flac',
    ];

    /**
     * Only a message the channel itself called audio. An MP3 arriving as a
     * document is far more likely to be a file the customer forwarded than a
     * question they recorded, and transcription is billed by the minute —
     * unlike an image sent as a file, which costs the same either way.
     */
    private const AUDIO_CARRIERS = [MessageType::Audio];

    /**
     * Ceiling on attachments per run. A customer who fires off eight
     * screenshots is not asking for eight images to be analysed — and every
     * one of them is billed input tokens.
     */
    public const MAX_PER_RUN = 4;

    /**
     * Build the hub payload for a set of messages, newest first, capped.
     *
     * @param  Collection<int, Message>|iterable<Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function forMessages(iterable $messages, int $limit = self::MAX_PER_RUN): array
    {
        return array_column(self::forMessagesWithSources($messages, $limit), 'attachment');
    }

    /**
     * The same list, each entry still paired with the message it came from.
     *
     * Callers that send audio need the pairing to put the transcription back
     * where it belongs once the hub answers — see AiTranscripts.
     *
     * @param  Collection<int, Message>|iterable<Message>  $messages
     * @return array<int, array{attachment: array<string, mixed>, message: Message}>
     */
    public static function forMessagesWithSources(iterable $messages, int $limit = self::MAX_PER_RUN): array
    {
        $entries = [];
        $audio = 0;
        $audioLimit = (int) config('ai.audio.max_per_run', 3);

        foreach ($messages as $message) {
            if (count($entries) >= $limit) {
                break;
            }

            $attachment = self::forMessage($message);

            if ($attachment === null) {
                continue;
            }

            if ($attachment['type'] === 'audio') {
                // Skipped rather than breaking the loop: the images behind a
                // fourth voice note are still worth sending.
                if ($audio >= $audioLimit) {
                    continue;
                }

                $audio++;
            }

            $entries[] = ['attachment' => $attachment, 'message' => $message];
        }

        return $entries;
    }

    /**
     * One attachment entry, or null when this message carries nothing the hub
     * could fetch — no file yet, not a medium it handles, or the retention
     * window closed and the URL would resolve to a 403.
     *
     * @return array<string, mixed>|null
     */
    public static function forMessage(Message $message): ?array
    {
        if (self::hasImage($message)) {
            return self::entry($message, [
                'type' => 'image',
                'detail' => 'auto',
            ]);
        }

        if (self::hasAudio($message)) {
            return self::entry($message, [
                'type' => 'audio',
                'mimeType' => self::AUDIO_MIME[self::extension($message->attachment)] ?? null,
            ]);
        }

        return null;
    }

    /** True when this message's stored file is an image we can hand over now. */
    public static function hasImage(Message $message): bool
    {
        if (! in_array($message->message_type, self::IMAGE_CARRIERS, true)) {
            return false;
        }

        if (! self::hasUsableFile($message)) {
            return false;
        }

        return in_array(self::extension($message->attachment), self::IMAGE_EXTENSIONS, true);
    }

    /** True when this message's stored file is a voice note we can hand over now. */
    public static function hasAudio(Message $message): bool
    {
        if (! self::audioEnabled() || ! in_array($message->message_type, self::AUDIO_CARRIERS, true)) {
            return false;
        }

        if (! self::hasUsableFile($message)) {
            return false;
        }

        if (! isset(self::AUDIO_MIME[self::extension($message->attachment)])) {
            return false;
        }

        $ceiling = (int) config('ai.audio.max_bytes', 0);

        // An unmeasured file is sent. attachment_size is written by an observer
        // the moment the attachment lands, so null here means a row older than
        // that observer — refusing on a number nobody recorded would silently
        // switch the feature off for a backlog instead of for a reason.
        return $ceiling <= 0
            || $message->attachment_size === null
            || $message->attachment_size <= $ceiling;
    }

    /**
     * True when this message will probably carry media once its download
     * lands — the signal a caller uses to decide whether stalling the AI for a
     * moment is worth it.
     *
     * Images qualify because the picture is usually what the text is about.
     * Audio qualifies for a stronger reason: a voice note has no text at all,
     * so answering before it arrives is not answering half the message, it is
     * answering none of it.
     *
     * A pending document is deliberately left out — it is unknowable until the
     * bytes arrive (a 40 MB PDF looks identical to a screenshot from here) and
     * holding a customer's reply hostage to that guess costs more than the
     * occasional missed image-sent-as-a-file.
     */
    public static function awaitingMedia(Message $message): bool
    {
        if ($message->attachment_status !== AttachmentStatus::Pending) {
            return false;
        }

        return $message->message_type === MessageType::Image
            || ($message->message_type === MessageType::Audio && self::audioEnabled());
    }

    /**
     * A one-line stand-in for a message that carries no text of its own.
     *
     * Something has to be said. The hub's `content` is where the customer's
     * turn goes, and sending "" for a bare screenshot asks the model to answer
     * silence — which it does, badly. Naming the medium at least lets the
     * agent respond to what happened ("I can't open videos, could you describe
     * it?") instead of inventing a message that was never sent.
     */
    public static function describe(Message $message): string
    {
        $type = $message->message_type?->value ?? 'message';

        return "[{$type}]";
    }

    public static function audioEnabled(): bool
    {
        return (bool) config('ai.audio.enabled', true);
    }

    /**
     * The `inputAudio` block that tells the hub to transcribe before it runs
     * the agent, or null when this run carries no audio to transcribe.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<string, mixed>|null
     */
    public static function inputAudioOptions(array $attachments): ?array
    {
        $carriesAudio = collect($attachments)->contains(fn ($attachment) => ($attachment['type'] ?? null) === 'audio');

        if (! $carriesAudio) {
            return null;
        }

        return array_filter([
            'transcriptionModel' => config('ai.audio.transcription_model'),
            'language' => config('ai.audio.language'),
            'prompt' => config('ai.audio.prompt'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** A file that exists, is ours to serve, and has not been purged. */
    private static function hasUsableFile(Message $message): bool
    {
        return (bool) $message->attachment
            && $message->attachment_status !== AttachmentStatus::Expired;
    }

    /**
     * The shared half of every entry: the link the hub fetches and the name it
     * shows. Null when the URL cannot be signed — a purged file, or media that
     * was never ours to serve.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     */
    private static function entry(Message $message, array $fields): ?array
    {
        $url = MediaRetention::signedUrl($message->attachment, $message, $message->conversation);

        if ($url === null) {
            return null;
        }

        return array_filter(array_merge($fields, [
            'url' => $url,
            'name' => self::fileName($message->attachment),
        ]), fn ($value) => $value !== null);
    }

    private static function extension(string $path): string
    {
        // Signed URLs and CDN links carry a query string; pathinfo would read
        // it as part of the extension.
        $path = strtok($path, '?') ?: $path;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private static function fileName(string $path): ?string
    {
        $name = basename(strtok($path, '?') ?: $path);

        return $name !== '' ? $name : null;
    }
}
