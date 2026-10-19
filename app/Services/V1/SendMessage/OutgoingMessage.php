<?php

namespace App\Services\V1\SendMessage;

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Services\Message\OutboundMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What one call to POST /v1/send-message asks to send: a text, or a file
 * named by URL with an optional caption.
 *
 * Every channel's handler reads its message through here, so the shape of the
 * body — `type`, `message`, `media_url`, `filename` — and what each channel can
 * do with it are decided in one place. ⚠️ The reference on the Developer page
 * (nuvemchat-fe-2 `pages/Developer.tsx`, SendMessageDocs) prints the two
 * tables below; change them together.
 *
 * The file is always a URL the channel (or, for Discord and TikTok, this
 * server) fetches. A multipart upload was left out on purpose: the integrator
 * already has the file somewhere public, and a second way to send the same
 * bytes would be a second set of size and type rules to keep in step.
 */
final class OutgoingMessage
{
    /** What `type` accepts. Absent means text — how every integration written before media read. */
    public const TYPES = ['text', 'image', 'video', 'audio', 'document'];

    private function __construct(
        public readonly string $type,
        public readonly ?string $text,
        public readonly ?OutboundMedia $media,
        private readonly ?string $filenameOverride,
    ) {}

    /**
     * Media each channel sends through the API. Text goes everywhere.
     *
     * TikTok is the one gap, and it is the platform's: its messaging API takes
     * text and images only.
     *
     * @return list<string>
     */
    public static function mediaTypes(Channel $channel): array
    {
        return match ($channel) {
            Channel::WhatsappOfficial,
            Channel::WhatsappApiway,
            Channel::Instagram,
            Channel::Messenger,
            Channel::Telegram,
            Channel::Discord => ['image', 'video', 'audio', 'document'],
            Channel::TikTok => ['image'],
            default => [],
        };
    }

    /**
     * Media that carries `message` as its caption.
     *
     * WhatsApp has no caption on audio, and Instagram, Messenger and TikTok
     * have none on anything. There a caption is refused rather than dropped:
     * an image that silently lost the sentence it was sent with reads to the
     * customer as a different message, and the integrator never learns why.
     *
     * @return list<string>
     */
    public static function captionTypes(Channel $channel): array
    {
        return match ($channel) {
            Channel::WhatsappOfficial, Channel::WhatsappApiway => ['image', 'video', 'document'],
            Channel::Telegram, Channel::Discord => ['image', 'video', 'audio', 'document'],
            default => [],
        };
    }

    /**
     * Validate the body and read the message out of it.
     *
     * @param  array<string, mixed>  $recipientRules  The channel's own addressing
     *                                                fields (`to`, `phone`, `chat_id`…), checked in the
     *                                                same pass so a body with two problems hears about both.
     *
     * @throws ValidationException
     */
    public static function fromData(Channel $channel, array $data, array $recipientRules): self
    {
        $type = $data['type'] ?? 'text';
        $isText = $type === 'text';

        validator($data, $recipientRules + [
            // Required as soon as a file is named: a media_url sent without it
            // would otherwise be read as a text and the file silently dropped.
            'type' => ['nullable', 'string', 'required_with:media_url', Rule::in(self::TYPES)],
            'message' => ['nullable', 'string', Rule::requiredIf($isText)],
            'media_url' => [
                'nullable',
                'string',
                'max:2048',
                'url:http,https',
                Rule::requiredIf(! $isText),
                // Only when `type` says text out loud — a missing `type` is
                // already reported above, once.
                Rule::prohibitedIf(($data['type'] ?? null) === 'text'),
            ],
            'filename' => ['nullable', 'string', 'max:150'],
        ])->validate();

        if ($isText) {
            return new self('text', $data['message'], null, null);
        }

        $label = self::channelLabel($channel);

        if (! in_array($type, self::mediaTypes($channel), true)) {
            $accepted = array_map(fn (string $t) => self::typeLabel($t), ['text', ...self::mediaTypes($channel)]);

            throw ValidationException::withMessages([
                'type' => "O {$label} não envia " . self::typeLabel($type) . ' pela API. Tipos aceitos: ' . implode(', ', $accepted) . '.',
            ]);
        }

        $caption = $data['message'] ?? null;

        if ($caption !== null && ! in_array($type, self::captionTypes($channel), true)) {
            throw ValidationException::withMessages([
                'message' => "O {$label} não aceita legenda em " . self::typeLabel($type)
                    . '. Envie a mídia sem message e o texto em outra requisição, com type text.',
            ]);
        }

        // The SSRF check lives in here (a media_url pointing inside our own
        // network is refused as a 422 on that field) — every send path funnels
        // through it, and so does this one.
        $media = OutboundMedia::fromData(['media_url' => $data['media_url']], 'file');

        $filename = isset($data['filename']) ? trim(basename(str_replace('\\', '/', $data['filename']))) : null;

        return new self($type, $caption, $media, $filename !== '' ? $filename : null);
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function messageType(): MessageType
    {
        return MessageType::from($this->type);
    }

    /** The text that rides with the file, if any. */
    public function caption(): ?string
    {
        return $this->isText() ? null : $this->text;
    }

    public function url(): string
    {
        return $this->media->url;
    }

    /**
     * The name the customer sees on a document: the one the integrator gave,
     * else the last segment of the URL.
     */
    public function filename(): string
    {
        return $this->filenameOverride ?? $this->media->filename;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->filename(), PATHINFO_EXTENSION) ?: $this->media->extension);
    }

    /**
     * The file itself, for the two channels that will not fetch a URL
     * (Discord, TikTok). It is a temp file: the caller unlinks it.
     *
     * Named after `filename` on a document when one was given; otherwise the
     * download's own name, which gains an extension from the Content-Type
     * when the URL had none.
     *
     * A file that cannot be fetched is the caller's address, not our failure,
     * so it comes back as a 422 on `media_url`.
     *
     * @throws ValidationException
     */
    public function download(): UploadedFile
    {
        $file = $this->media->toUploadedFile();

        if (! $file) {
            throw ValidationException::withMessages([
                'media_url' => 'Não foi possível baixar o arquivo de media_url. Confira se o endereço está acessível sem login.',
            ]);
        }

        if ($this->type === 'document' && $this->filenameOverride !== null) {
            return new UploadedFile($file->getRealPath(), $this->filenameOverride, $file->getClientMimeType(), null, true);
        }

        return $file;
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'text' => 'texto',
            'image' => 'imagem',
            'video' => 'vídeo',
            'audio' => 'áudio',
            'document' => 'documento',
            default => $type,
        };
    }

    private static function channelLabel(Channel $channel): string
    {
        return match ($channel) {
            Channel::WhatsappOfficial => 'WhatsApp Oficial',
            Channel::WhatsappApiway => 'WhatsApp API Way',
            Channel::Instagram => 'Instagram',
            Channel::Messenger => 'Messenger',
            Channel::Telegram => 'Telegram',
            Channel::Discord => 'Discord',
            Channel::TikTok => 'TikTok',
            default => $channel->value,
        };
    }
}
