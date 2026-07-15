<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use App\Services\Message\OutboundMedia;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;

class TelegramHandler implements MessageHandlerInterface
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
        $telegram = new Api($connection->credentials['token']);

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
            $telegram = new Api($connection->credentials['token']);

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

            throw new Exception('Failed to send Telegram message');
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
                    throw new Exception('Failed to send Telegram image by URL and download fallback failed');
                }
                $data['image'] = $file;
            }
        }

        try {
            $telegram = new Api($connection->credentials['token']);

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

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['image']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, file_get_contents($data['image']->getRealPath()));

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

            throw new Exception('Failed to send Telegram image message');
        }
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'audio' => 'required_without:media_url|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'media_url' => 'required_without:audio|url',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'audio');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl($conversation, $data, 'voice', 'sendVoice', MessageType::Audio, $media->url);
            } catch (\Throwable $th) {
                Log::warning('TelegramHandler: URL audio send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send Telegram audio by URL and download fallback failed');
                }
                $data['audio'] = $file;
            }
        }

        try {
            $telegram = new Api($connection->credentials['token']);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'chat_id' => $conversation->external_id,
                'voice' => fopen($data['audio']->getRealPath(), 'r'),
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

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['audio']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, file_get_contents($data['audio']->getRealPath()));

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

            throw new Exception('Failed to send Telegram audio message');
        }
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
                    throw new Exception('Failed to send Telegram video by URL and download fallback failed');
                }
                $data['video'] = $file;
            }
        }

        try {
            $telegram = new Api($connection->credentials['token']);

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

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['video']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, file_get_contents($data['video']->getRealPath()));

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

            throw new Exception('Failed to send Telegram video message');
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
                    throw new Exception('Failed to send Telegram document by URL and download fallback failed');
                }
                $data['document'] = $file;
            }
        }

        try {
            $telegram = new Api($connection->credentials['token']);

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

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['document']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, file_get_contents($data['document']->getRealPath()));

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

            throw new Exception('Failed to send Telegram document message');
        }
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        // Telegram hanya support edit text message
        if ($message->message_type !== MessageType::Text) {
            throw new Exception('Only text messages can be edited on Telegram');
        }

        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            $telegram = new Api($connection->credentials['token']);
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

            throw new Exception('Failed to edit Telegram message: ' . $th->getMessage());
        }
    }

    public function handleDeleteMessage(Message $message): bool
    {
        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            $telegram = new Api($connection->credentials['token']);
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

            throw new Exception('Failed to delete Telegram message: ' . $th->getMessage());
        }
    }
}
