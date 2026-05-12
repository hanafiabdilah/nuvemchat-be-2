<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsappOfficialHandler implements MessageHandlerInterface
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    public function getMessageId(array $payload): string
    {
        return $payload['messages'][0]['id'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now(); // Cloud API returns no timestamp on send
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $conversation->external_id,
                    'type' => 'text',
                    'text' => [
                        'body' => $data['message'],
                    ],
                ]);

            $responseArray = $response->json();

            if(!$response->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $responseArray,
                    'conversation_id' => $conversation->id,
                    'connection_id' => $connection->id,
                ]);

                throw new Exception($responseArray['error']['message'] ?? 'Failed to send WhatsApp message');
            }

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
            'message' => 'nullable|string',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            $data['image'],
            'image',
            MessageType::Image,
            'images',
            ['caption' => $data['message'] ?? null],
            $data['message'] ?? null,
        );
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'audio' => 'required|file|mimes:aac,m4a,mp3,ogg,opus,amr|max:16384',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            $data['audio'],
            'audio',
            MessageType::Audio,
            'audios',
            [],
            null,
        );
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'video' => 'required|file|mimes:mp4,3gp|max:16384',
            'message' => 'nullable|string',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            $data['video'],
            'video',
            MessageType::Video,
            'videos',
            ['caption' => $data['message'] ?? null],
            $data['message'] ?? null,
        );
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip|max:102400',
            'message' => 'nullable|string',
        ])->validate();

        $filename = $data['document']->getClientOriginalName();

        return $this->sendMediaByLink(
            $conversation,
            $data['document'],
            'document',
            MessageType::Document,
            'documents',
            [
                'caption' => $data['message'] ?? null,
                'filename' => $filename,
            ],
            $data['message'] ?? null,
            ['filename' => $filename],
        );
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        throw new Exception('Message editing not supported for WhatsApp Official API');
    }

    public function handleDeleteMessage(Message $message): bool
    {
        throw new Exception('Message deletion not supported for WhatsApp Official API');
    }

    /**
     * Host the uploaded file at a public URL and send it as a Cloud API media
     * message with `link`. Mirrors the Instagram URL-based send pattern so the
     * file is reachable to Meta's fetcher. The same bytes are then persisted
     * to the local disk as the canonical attachment.
     */
    private function sendMediaByLink(
        Conversation $conversation,
        $uploadedFile,
        string $mediaType,
        MessageType $messageTypeEnum,
        string $publicSubdir,
        array $extraMediaFields,
        ?string $body,
        array $extraMeta = [],
    ): ?Message {
        $connection = $conversation->connection;
        $tempPublicPath = null;

        try {
            $content = file_get_contents($uploadedFile->getRealPath());
            $extension = $uploadedFile->getClientOriginalExtension();

            $tempFileName = 'temp_' . uniqid() . '.' . $extension;
            $tempPublicPath = $publicSubdir . '/' . $tempFileName;
            Storage::disk('public')->put($tempPublicPath, $content);

            $publicUrl = url('storage/' . $tempPublicPath);

            $mediaPayload = array_filter(
                array_merge(['link' => $publicUrl], $extraMediaFields),
                fn($v) => $v !== null && $v !== '',
            );

            $response = Http::withToken($connection->credentials['access_token'])
                ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $conversation->external_id,
                    'type' => $mediaType,
                    $mediaType => $mediaPayload,
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to send media', [
                    'media_type' => $mediaType,
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);

                throw new Exception($responseArray['error']['message'] ?? "Failed to send WhatsApp {$mediaType}");
            }

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => $messageTypeEnum,
                'body' => $body,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, $extraMeta),
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $content);

            $message->update(['attachment' => $mediaPath]);

            return $message;
        } catch (\Throwable $th) {
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            Log::error('WhatsappOfficialHandler: Failed to send media', [
                'media_type' => $mediaType,
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception("Failed to send WhatsApp {$mediaType}: " . $th->getMessage());
        }
    }
}
