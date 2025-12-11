<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TelegramHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['message']['chat']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['message']['message_id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return $payload['message']['text'] ?? $payload['message']['caption'] ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        if (isset($payload['message']['text'])) {
            return MessageType::Text;
        } elseif (isset($payload['message']['voice'])) {
            return MessageType::Audio;
        } elseif (isset($payload['message']['photo'])) {
            return MessageType::Image;
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['message']['date'])) return Carbon::createFromTimestamp($payload['message']['date']);

        return Carbon::now();
    }

    public function handle(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);

        if (!$conversationId || !$messageId || !$messageType) return;

        $conversation = Conversation::firstOrCreate([
            'connection_id' => $connection->id,
            'external_id'   => $conversationId,
        ]);

        if(Message::where('external_id', $messageId)->exists()) return;

        $message = $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Incoming,
            'message_type' => $messageType,
            'body' => $this->getMessageBody($payload),
            'sent_at' => $this->getMessageSentAt($payload),
            'meta' => $payload,
        ]);

         if(in_array($messageType, [MessageType::Audio, MessageType::Image])) {
            $this->handleMediaMessage($message, $payload, $messageType);
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Audio => 'voice',
            MessageType::Image => 'photo',
            default => null,
        };

        $media = $payload['message'][$mediaKey];

        if(isset($media[0])) {
            $media = $payload['message'][$mediaKey][count($payload['message'][$mediaKey]) - 1];
        }

        $response = Http::get("https://api.telegram.org/bot{$message->conversation->connection->credentials['token']}/getFile", [
            'file_id' => $media['file_id'],
        ]);

        if ($response->failed()) return;

        $filePath = $response->json('result.file_path');
        $fileUrl = "https://api.telegram.org/file/bot{$message->conversation->connection->credentials['token']}/{$filePath}";
        $extension = $this->getExtensionFromFilePath($filePath);

        if(!$fileUrl || !$extension) return;

        $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;

        Storage::disk('local')->put($mediaPath, Http::get($fileUrl)->body());

        $message->update([
            'attachment' => $mediaPath,
        ]);
    }

    private function getExtensionFromFilePath(string $filePath): ?string
    {
        $parts = explode('.', $filePath);

        return end($parts) ?: null;
    }
}
