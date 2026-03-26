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
use Illuminate\Support\Facades\Log;
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
        if (in_array($payload['message']['chat']['type'] ?? null, ['group', 'supergroup'])) {
            Log::info('TelegramHandler: Skipping group message');
            return;
        }

        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);

        if (!$conversationId || !$messageId || !$contactExternalId || !$contactName){
            Log::warning('TelegramHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
                'contact_name' => $contactName,
            ]);

            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);
            if($contact->wasRecentlyCreated) $this->savePhotoProfile($contact, $connection, $payload);

            $conversation = Conversation::firstOrCreate([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id'   => $conversationId,
            ]);

            return $conversation->messages()->updateOrCreate([
                'external_id' => $messageId,
            ], [
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->wasRecentlyCreated ? $message->conversation->load('contact') : $message->conversation));
        }
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

    private function savePhotoProfile(Contact $contact, Connection $connection, array $payload)
    {
        $response = Http::get("https://api.telegram.org/bot{$connection->credentials['token']}/getUserProfilePhotos", [
            'user_id' => $contact->external_id,
        ]);

        if ($response->failed()){
            Log::warning('TelegramHandler: Failed to fetch profile photos', [
                'contact_id' => $contact->id,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);

            return;
        }

        $photos = $response->json('result.photos');

        if (empty($photos)) {
            Log::info('TelegramHandler: No profile photos found for contact', [
                'contact_id' => $contact->id,
            ]);

            return;
        }

        $photo = $photos[0][count($photos[0]) - 1]; // Get the highest resolution photo

        $fileResponse = Http::get("https://api.telegram.org/bot{$connection->credentials['token']}/getFile", [
            'file_id' => $photo['file_id'],
        ]);

        if ($fileResponse->failed()){
            Log::warning('TelegramHandler: Failed to fetch profile photo file info', [
                'contact_id' => $contact->id,
                'response_status' => $fileResponse->status(),
                'response_body' => $fileResponse->body(),
            ]);

            return;
        }

        $filePath = $fileResponse->json('result.file_path');
        $fileUrl = "https://api.telegram.org/file/bot{$connection->credentials['token']}/{$filePath}";
        $extension = $this->getExtensionFromFilePath($filePath);

        if(!$fileUrl || !$extension) {
            Log::warning('TelegramHandler: Invalid file URL or extension for profile photo', [
                'contact_id' => $contact->id,
                'file_url' => $fileUrl,
                'extension' => $extension,
            ]);

            return;
        }

        $photoPath = 'profile_photos/' . $contact->id . '_' . uniqid() . '.' . $extension;
        Storage::disk('local')->put($photoPath, Http::get($fileUrl)->body());

        $contact->update([
            'photo_profile' => $photoPath,
        ]);
    }

    private function getExtensionFromFilePath(string $filePath): ?string
    {
        $parts = explode('.', $filePath);

        return end($parts) ?: null;
    }
}
