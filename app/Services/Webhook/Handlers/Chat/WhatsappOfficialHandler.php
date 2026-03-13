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

class WhatsappOfficialHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $messageType = $this->getMessageType($payload);

        if ($messageType === MessageType::Image) {
            return $payload['entry'][0]['changes'][0]['value']['messages'][0]['image']['caption'] ?? null;
        }

        return $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        return match($payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] ?? null) {
            'text' => MessageType::Text,
            'image' => MessageType::Image,
            'video' => MessageType::Video,
            'document' => MessageType::Document,
            'audio' => MessageType::Audio,
            default => MessageType::Unsupported,
        };
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'])) return Carbon::createFromTimestamp($payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp']);

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? '';
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['username'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);

        if (!$conversationId || !$messageId || !$contactExternalId || !$contactName){
            Log::warning('WhatsappOfficialHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
                'contact_name' => $contactName,
            ]);
            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

            $conversation = Conversation::firstOrCreate([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id'   => $conversationId,
            ]);

            if(Message::where('external_id', $messageId)->lockForUpdate()->exists()) return;

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));

            if(in_array($messageType, [MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Audio])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Image => 'image',
            MessageType::Video => 'video',
            MessageType::Document => 'document',
            MessageType::Audio => 'audio',
            default => null,
        };

        if(!$mediaKey) return;

        $mediaUrl = $payload['entry'][0]['changes'][0]['value']['messages'][0][$mediaKey]['url'];
        $extension = $this->getExtensionFromMimeType($payload['entry'][0]['changes'][0]['value']['messages'][0][$mediaKey]['mime_type']);

        if(!$mediaUrl || !$extension) return;

        $connectionCredentials = $message->conversation->connection->credentials;
        $accessToken = $connectionCredentials['access_token'] ?? null;
        $phoneNumberId = $connectionCredentials['phone_number_id'] ?? null;

        if(!$accessToken || !$phoneNumberId) return;

        $response = Http::withToken($accessToken)->get($mediaUrl);

        if(!$response->successful()) return;

        $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
        Storage::disk('local')->put($mediaPath, $response->body());

        $message->update([
            'attachment' => $mediaPath,
        ]);
    }

    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        return match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/json' => 'json',
            'application/x-zip-compressed' => 'zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'audio/ogg; codecs=opus' => 'ogg',
            'audio/ogg' => 'ogg',
            default => null,
        };
    }
}
