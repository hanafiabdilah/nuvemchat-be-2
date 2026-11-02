<?php

namespace App\Services\Message\Handlers;

use App\Exceptions\ChannelCapabilityException;
use App\Enums\Message\MessageType;
use App\Enums\Message\PresenceKind;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use App\Services\Message\AudioNormalizer;
use App\Services\Message\Contracts\SendsTypingIndicator;
use App\Services\Message\MessageHandlerInterface;
use App\Services\Message\OutboundMedia;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\HttpClientInterface;

class TelegramHandler implements MessageHandlerInterface, SendsTypingIndicator
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'];
    }

    /**
     * URL fast-path: Telegram accepts an HTTP URL in place of an uploaded file,
     * so pass the caller's URL directly and store it as the attachment without
     * downloading/persisting bytes. Throws on failure so the caller can fall
     * back to downloading the file (e.g. when Telegram cannot fetch the URL or
     * the format is unsupported for voice).
     */
    private function sendMediaByUrl(
        Conversation $conversation,
        array $data,
        string $field,
        string $sdkMethod,
        MessageType $messageTypeEnum,
        string $url,
        array $extraMeta = [],
        ?string $body = null,
    ): Message {
        $connection = $conversation->connection;
        $telegram = $this->api($connection);

        $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

        $payload = [
            'chat_id' => $conversation->external_id,
            $field => $url,
        ];

        if ($body !== null) {
            $payload['caption'] = $body;
        }

        if ($repliedMessageExternalId) {
            $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
        }

        $response = $telegram->{$sdkMethod}($payload);
        $responseArray = $response->toArray();

        $message = $conversation->messages()->create([
            'external_id' => $this->getMessageId($responseArray),
            'sender_type' => SenderType::Outgoing,
            'message_type' => $messageTypeEnum,
            'body' => $body,
            'replied_message_id' => $data['replied_message_id'] ?? null,
            'sent_at' => $this->getMessageSentAt($responseArray),
            'delivery_at' => $this->getMessageSentAt($responseArray),
            'meta' => array_merge($responseArray, $extraMeta),
        ]);

        $message->update(['attachment' => $url]);

        return $message;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['date'])) return Carbon::createFromTimestamp($payload['date']);

        return Carbon::now();
    }

    private function getRepliedMessageExternalId(Conversation $conversation, ?int $repliedMessageId): ?string
    {
        if (!$repliedMessageId) {
            return null;
        }

        $repliedMessage = Message::where('id', $repliedMessageId)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (!$repliedMessage) {
            Log::warning('TelegramHandler: Replied message not found', [
                'replied_message_id' => $repliedMessageId,
                'conversation_id' => $conversation->id,
            ]);
            return null;
        }

        return $repliedMessage->external_id;
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $telegram = $this->api($connection);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'text' => $data['message'],
            ];

            if ($repliedMessageExternalId) {
                $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
            }

            $response = $telegram->sendMessage($payload);

            $responseArray = $response->toArray();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram message', 0, $th);
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'media_url' => 'required_without:image|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'image');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl($conversation, $data, 'photo', 'sendPhoto', MessageType::Image, $media->url, [], $data['message'] ?? null);
            } catch (\Throwable $th) {
                Log::warning('TelegramHandler: URL image send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send Telegram image by URL and download fallback failed', 0, $th);
                }
                $data['image'] = $file;
            }
        }

        try {
            $telegram = $this->api($connection);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'photo' => fopen($data['image']->getRealPath(), 'r'),
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
            }

            $response = $telegram->sendPhoto($payload);

            $responseArray = $response->toArray();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Image,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            $mediaPath = MediaFilename::path('media', (string) $message->id, $data['image']->getClientOriginalName(), $data['image']->getClientOriginalExtension());
            MediaStorage::disk()->put($mediaPath, file_get_contents($data['image']->getRealPath()));

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send image message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram image message', 0, $th);
        }
    }

    /**
     * What Telegram plays as a voice note, read off the bytes — never off the
     * name the browser gave them.
     *
     * The Bot API takes OGG/Opus, MP3 or M4A for `sendVoice`, and the trap is
     * that it accepts everything else too: the file is stored as a voice note
     * labelled audio/ogg with a duration of 0, and the recipient's app cannot
     * play it while a browser opening the same file plays it fine. Recordings
     * are exactly "everything else". Chrome and the iOS app (WebKit) record
     * WebM — WebKit's is written as a live stream, with no duration and no
     * index, and that is the one Telegram's apps refuse outright — and an iOS
     * that cannot record WebM hands over MP4, which the composer names .m4a but
     * which sniffs as video/mp4. All of them are re-encoded; the formats on
     * Telegram's list pass through untouched, so the AI's mp3 voice replies
     * never depend on ffmpeg.
     *
     * `.opus` is the same Ogg container under another name; it only ever shows
     * up on a URL, which is judged by its extension because Telegram fetches it
     * itself.
     */
    private const VOICE_FORMATS = ['ogg', 'opus', 'mp3', 'm4a'];

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            // mp4 because that is what an iPhone records when it cannot record
            // WebM: named .m4a by the composer, but this rule reads the bytes,
            // and they sniff as video/mp4. It is converted below, not refused.
            'audio' => 'required_without:media_url|file|mimes:ogg,mp3,wav,m4a,mp4,opus,webm|max:16384',
            'media_url' => 'required_without:audio|url',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;
        $downloaded = null;

        $media = OutboundMedia::fromData($data, 'audio');
        if ($media && $media->isUrl()) {
            $urlFailure = null;

            // Only a URL Telegram can play is offered as a URL; anything else
            // would be fetched, accepted, and delivered as a voice note nobody
            // can play, so it is pulled down and converted like an upload.
            if (in_array(strtolower($media->extension), self::VOICE_FORMATS, true)) {
                try {
                    return $this->sendMediaByUrl($conversation, $data, 'voice', 'sendVoice', MessageType::Audio, $media->url);
                } catch (\Throwable $th) {
                    Log::warning('TelegramHandler: URL audio send failed, falling back to download', [
                        'error' => $th->getMessage(),
                        'conversation_id' => $conversation->id,
                    ]);
                    $urlFailure = $th;
                }
            }

            $downloaded = $media->toUploadedFile();
            if (!$downloaded) {
                throw new Exception('Failed to download the Telegram audio from its URL', 0, $urlFailure);
            }
            $data['audio'] = $downloaded;
        }

        // Kept apart from the send's try below on purpose: a recording that
        // cannot be converted is refused in our own words, and the catch there
        // would flatten that into "failed to send".
        try {
            $voice = $this->voiceFile($data['audio']);
        } catch (\Throwable $th) {
            if ($downloaded !== null) {
                @unlink($downloaded->getRealPath());
            }

            throw $th;
        }

        try {
            $telegram = $this->api($connection);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'voice' => fopen($voice->getRealPath(), 'r'),
            ];

            if ($repliedMessageExternalId) {
                $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
            }

            $response = $telegram->sendVoice($payload);

            $responseArray = $response->toArray();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Audio,
                'body' => null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            // The copy kept is the one the customer received, as on WhatsApp:
            // what the thread plays back is what was delivered.
            $mediaPath = MediaFilename::path('media', (string) $message->id, $voice->getClientOriginalName(), $voice->getClientOriginalExtension());
            MediaStorage::disk()->put($mediaPath, file_get_contents($voice->getRealPath()));

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send audio message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram audio message', 0, $th);
        } finally {
            // Temp files this request created; nothing else will collect them.
            if ($voice !== $data['audio']) {
                @unlink($voice->getRealPath());
            }
            if ($downloaded !== null) {
                @unlink($downloaded->getRealPath());
            }
        }
    }

    /**
     * The file to hand to sendVoice: the upload itself when Telegram can play
     * it, otherwise a mono Ogg/Opus re-encode of it. See VOICE_FORMATS.
     *
     * ⚠️ A converted file is a temp file; the caller unlinks it.
     */
    private function voiceFile(UploadedFile $file): UploadedFile
    {
        $format = strtolower((string) $file->guessExtension());

        if (in_array($format, self::VOICE_FORMATS, true)) {
            return $file;
        }

        Log::info('TelegramHandler: re-encoding audio to Ogg/Opus for sendVoice', [
            'format' => $format !== '' ? $format : null,
            'filename' => $file->getClientOriginalName(),
        ]);

        return app(AudioNormalizer::class)->toOggOpus($file);
    }

    /**
     * The SDK client for a connection's bot.
     *
     * The HTTP handler comes from the container only when something bound it,
     * and nothing does outside tests — so in production this is the SDK's own
     * Guzzle client, exactly what `new Api($token)` was. The seam exists
     * because that client is out of reach of Http::fake(), and resolving
     * `Api::class` is not a way round it: the SDK binds it to the default bot
     * in config/telegram.php, so a token passed to the container is ignored.
     */
    private function api(Connection $connection): Api
    {
        return new Api(
            $connection->credentials['token'],
            false,
            app()->bound(HttpClientInterface::class) ? app(HttpClientInterface::class) : null,
        );
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'video' => 'required_without:media_url|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'media_url' => 'required_without:video|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'video');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl($conversation, $data, 'video', 'sendVideo', MessageType::Video, $media->url, [], $data['message'] ?? null);
            } catch (\Throwable $th) {
                Log::warning('TelegramHandler: URL video send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send Telegram video by URL and download fallback failed', 0, $th);
                }
                $data['video'] = $file;
            }
        }

        try {
            $telegram = $this->api($connection);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'video' => fopen($data['video']->getRealPath(), 'r'),
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
            }

            $response = $telegram->sendVideo($payload);

            $responseArray = $response->toArray();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Video,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            $mediaPath = MediaFilename::path('media', (string) $message->id, $data['video']->getClientOriginalName(), $data['video']->getClientOriginalExtension());
            MediaStorage::disk()->put($mediaPath, file_get_contents($data['video']->getRealPath()));

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send video message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram video message', 0, $th);
        }
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'document' => 'required_without:media_url|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'media_url' => 'required_without:document|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'document');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl($conversation, $data, 'document', 'sendDocument', MessageType::Document, $media->url, ['filename' => $media->filename], $data['message'] ?? null);
            } catch (\Throwable $th) {
                Log::warning('TelegramHandler: URL document send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send Telegram document by URL and download fallback failed', 0, $th);
                }
                $data['document'] = $file;
            }
        }

        try {
            $telegram = $this->api($connection);

            // Get original filename
            $filename = $data['document']->getClientOriginalName();

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'document' => fopen($data['document']->getRealPath(), 'r'),
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['reply_to_message_id'] = (int)$repliedMessageExternalId;
            }

            $response = $telegram->sendDocument($payload);

            $responseArray = $response->toArray();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Document,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, ['filename' => $filename]),
            ]);

            $mediaPath = MediaFilename::path('media', (string) $message->id, $filename, $data['document']->getClientOriginalExtension());
            MediaStorage::disk()->put($mediaPath, file_get_contents($data['document']->getRealPath()));

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send document message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram document message', 0, $th);
        }
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        // Telegram hanya support edit text message
        if ($message->message_type !== MessageType::Text) {
            throw new ChannelCapabilityException('No Telegram, apenas mensagens de texto podem ser editadas.');
        }

        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            $telegram = $this->api($connection);
            $response = $telegram->editMessageText([
                'chat_id' => $conversation->external_id,
                'message_id' => $message->external_id,
                'text' => $data['message'],
            ]);

            $responseArray = $response->toArray();

            // Update message di database
            $message->update([
                'body' => $data['message'],
                'edited_at' => Carbon::now(),
            ]);

            Log::info('TelegramHandler: Message edited successfully', [
                'message_id' => $message->id,
                'external_id' => $message->external_id,
                'conversation_id' => $conversation->id,
            ]);

            return $message->fresh();
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to edit message', [
                'error' => $th->getMessage(),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to edit Telegram message: ' . $th->getMessage(), 0, $th);
        }
    }

    public function handleDeleteMessage(Message $message): bool
    {
        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            $telegram = $this->api($connection);
            $telegram->deleteMessage([
                'chat_id' => $conversation->external_id,
                'message_id' => $message->external_id,
            ]);

            Log::info('TelegramHandler: Message deleted successfully', [
                'message_id' => $message->id,
                'external_id' => $message->external_id,
                'conversation_id' => $conversation->id,
            ]);

            $message->update([
                'unsend_at' => Carbon::now(),
            ]);

            return true;
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to delete message', [
                'error' => $th->getMessage(),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to delete Telegram message: ' . $th->getMessage(), 0, $th);
        }
    }

    /**
     * Telegram's chat action clears itself after 5 seconds — by far the
     * shortest of any channel here, which is the whole reason the refresh
     * interval is per-channel rather than one number for everyone. There is no
     * "stop" action; the countdown is the stop.
     */
    public function handleTyping(
        Conversation $conversation,
        bool $typing = true,
        PresenceKind $kind = PresenceKind::Typing,
    ): bool {
        if (!$typing) {
            return false;
        }

        return $this->api($conversation->connection)->sendChatAction([
            'chat_id' => $conversation->external_id,
            // The one channel that draws all three states, so it gets the one
            // the bubble deserves: "gravando mensagem de voz" ahead of a voice
            // note is a promise about what is coming, and "digitando" there is
            // the wrong promise.
            'action' => $kind->telegramAction(),
        ]);
    }
}
