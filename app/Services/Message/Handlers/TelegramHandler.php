<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
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
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

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
            'audio' => 'required|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

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
            'video' => 'required|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

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
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

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
