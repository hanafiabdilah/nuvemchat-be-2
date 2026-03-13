<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
        } elseif(isset($payload['message']['video'])) {
            return MessageType::Video;
        } elseif(isset($payload['message']['document'])) {
            return MessageType::Document;
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['message']['date'])) return Carbon::createFromTimestamp($payload['message']['date']);

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        if (isset($payload['message']['from']['first_name']) && isset($payload['message']['from']['last_name'])) {
            return $payload['message']['from']['first_name'] . ' ' . $payload['message']['from']['last_name'];
        }

        return $payload['message']['from']['first_name'] ?? '';
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['message']['from']['username'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['message']['from']['id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        DB::transaction(function() use ($connection, $payload) {
            $conversationId = $this->getConversationId($payload);
            $messageId = $this->getMessageId($payload);
            $messageType = $this->getMessageType($payload);
            $contactExternalId = $this->getContactExternalId($payload);
            $contactName = $this->getContactName($payload);
            $contactUsername = $this->getContactUsername($payload);

            if (!$conversationId || !$messageId || !$contactExternalId || !$contactName) return;

            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

            $conversation = Conversation::firstOrCreate([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id'   => $conversationId,
            ]);

            if(Message::where('external_id', $messageId)->lockForUpdate()->exists()) return;

            $message = $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($conversation));

            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }
        });
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Audio => 'voice',
            MessageType::Image => 'photo',
            MessageType::Video => 'video',
            MessageType::Document => 'document',
            default => null,
        };

        $media = $payload['message'][$mediaKey];

        if(isset($media[0])) {
            $media = $payload['message'][$mediaKey][count($payload['message'][$mediaKey]) - 1];
        }

        $response = Http::get("https://api.telegram.org/bot{$message->conversation->connection->credentials['token']}/getFile", [
            'file_id' => $media['file_id'],
        ]);

        if ($response->failed()){
            if($response->status() === 400) {
                $message->update([
                    'error' => $response->json('description'),
                ]);
            }

            return;
        }

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
