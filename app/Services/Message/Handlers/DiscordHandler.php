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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Outbound Discord sends via the REST API (Bot token). The conversation's
 * external_id is the DM channel id captured on the first inbound message.
 * Discord has no remote-URL attachments, so media is always uploaded as
 * multipart bytes (media_url inputs are downloaded first). Unlike the Meta
 * channels, Discord supports replies, edits and deletes.
 */
class DiscordHandler implements MessageHandlerInterface
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function getMessageId(array $payload): string
    {
        return (string) ($payload['id'] ?? uniqid('dc_', true));
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (!empty($payload['timestamp'])) {
            try {
                return Carbon::parse($payload['timestamp']);
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $payload = ['content' => $data['message']];

            $repliedExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);
            if ($repliedExternalId) {
                // fail_if_not_exists=false: send normally if the target was deleted.
                $payload['message_reference'] = [
                    'message_id' => $repliedExternalId,
                    'fail_if_not_exists' => false,
                ];
            }

            $response = Http::withHeaders($this->authHeaders($connection))
                ->post(self::API_BASE . "/channels/{$conversation->external_id}/messages", $payload);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('DiscordHandler: Failed to send message', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['message'] ?? 'Failed to send Discord message');
            }

            return $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Discord message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'media_url' => 'required_without:image|url',
            'caption' => 'nullable|string',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'image', MessageType::Image);
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'audio' => 'required_without:media_url|file|mimes:aac,m4a,wav,mp4,mp3,ogg,opus,webm|max:10240',
            'media_url' => 'required_without:audio|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'audio', MessageType::Audio);
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'video' => 'required_without:media_url|file|mimes:mp4,ogg,avi,mov,webm|max:10240',
            'media_url' => 'required_without:video|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'video', MessageType::Video);
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'document' => 'required_without:media_url|file|max:10240',
            'media_url' => 'required_without:document|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'document', MessageType::Document);
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $conversation = $message->conversation;
        $connection = $conversation->connection;

        $response = Http::withHeaders($this->authHeaders($connection))
            ->patch(self::API_BASE . "/channels/{$conversation->external_id}/messages/{$message->external_id}", [
                'content' => $data['message'],
            ]);

        if (!$response->successful()) {
            Log::error('DiscordHandler: Failed to edit message', [
                'message_id' => $message->id,
                'response' => $response->json(),
            ]);
            throw new Exception($response->json()['message'] ?? 'Failed to edit Discord message');
        }

        $message->update([
            'body' => $data['message'],
            'edited_at' => Carbon::now(),
        ]);

        return $message->fresh();
    }

    public function handleDeleteMessage(Message $message): bool
    {
        $conversation = $message->conversation;
        $connection = $conversation->connection;

        $response = Http::withHeaders($this->authHeaders($connection))
            ->delete(self::API_BASE . "/channels/{$conversation->external_id}/messages/{$message->external_id}");

        if (!$response->successful() && $response->status() !== 404) {
            Log::error('DiscordHandler: Failed to delete message', [
                'message_id' => $message->id,
                'response' => $response->json(),
            ]);
            throw new Exception($response->json()['message'] ?? 'Failed to delete Discord message');
        }

        $message->update([
            'unsend_at' => Carbon::now(),
        ]);

        return true;
    }

    /**
     * Shared media pipeline: Discord only accepts uploaded bytes (no
     * send-by-URL), so media_url inputs are downloaded and re-uploaded as
     * multipart. The caption (images) rides along as `content`.
     */
    private function handleSendMedia(
        Conversation $conversation,
        array $data,
        string $fileKey,
        MessageType $messageTypeEnum,
    ): ?Message {
        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, $fileKey);
        if ($media && $media->isUrl()) {
            $file = $media->toUploadedFile();
            if (!$file) {
                throw new Exception("Failed to download {$fileKey} from media_url for Discord upload");
            }
            $data[$fileKey] = $file;
        }

        try {
            $content = file_get_contents($data[$fileKey]->getRealPath());
            $filename = $data[$fileKey]->getClientOriginalName()
                ?: ($fileKey . '.' . ($data[$fileKey]->getClientOriginalExtension() ?: 'bin'));

            $payloadJson = [
                'attachments' => [['id' => 0, 'filename' => $filename]],
            ];

            $caption = trim((string) ($data['caption'] ?? ''));
            if ($caption !== '') {
                $payloadJson['content'] = $caption;
            }

            $response = Http::withHeaders($this->authHeaders($connection))
                ->attach('files[0]', $content, $filename)
                ->post(self::API_BASE . "/channels/{$conversation->external_id}/messages", [
                    'payload_json' => json_encode($payloadJson),
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error("DiscordHandler: Failed to send {$fileKey}", [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['message'] ?? "Failed to send Discord {$fileKey}");
            }

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => $messageTypeEnum,
                'body' => $caption !== '' ? $caption : null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, ['filename' => $filename]),
            ]);

            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $content);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error("DiscordHandler: Failed to send {$fileKey}", [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception("Failed to send Discord {$fileKey}: " . $th->getMessage());
        }
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
            Log::warning('DiscordHandler: Replied message not found', [
                'replied_message_id' => $repliedMessageId,
                'conversation_id' => $conversation->id,
            ]);
            return null;
        }

        return $repliedMessage->external_id;
    }

    private function authHeaders($connection): array
    {
        return ['Authorization' => 'Bot ' . ($connection->credentials['token'] ?? '')];
    }
}
