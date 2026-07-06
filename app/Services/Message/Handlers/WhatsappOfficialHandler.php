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
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg|max:5120',
            'media_url' => 'required_without:image|url',
            'message' => 'nullable|string',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            OutboundMedia::fromData($data, 'image'),
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
            'audio' => 'required_without:media_url|file|mimes:aac,m4a,wav,mp4,mp3,ogg,opus,webm|max:25600',
            'media_url' => 'required_without:audio|url',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            OutboundMedia::fromData($data, 'audio'),
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
            'video' => 'required_without:media_url|file|mimes:mp4,ogg,avi,mov,webm|max:25600',
            'media_url' => 'required_without:video|url',
            'message' => 'nullable|string',
        ])->validate();

        return $this->sendMediaByLink(
            $conversation,
            OutboundMedia::fromData($data, 'video'),
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
            'document' => 'required_without:media_url|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip|max:102400',
            'media_url' => 'required_without:document|url',
            'message' => 'nullable|string',
        ])->validate();

        $media = OutboundMedia::fromData($data, 'document');
        $filename = $media?->filename ?? 'document';

        return $this->sendMediaByLink(
            $conversation,
            $media,
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
     * Send a media message with Cloud API `link`.
     *
     * URL fast-path: reference the caller's URL directly (Meta fetches it), store
     * the URL as the attachment, no bytes hosted/persisted. If Meta rejects the
     * URL, fall back once to downloading it and hosting the bytes.
     * File path: host the uploaded bytes on the public disk to get a reachable
     * link, then persist the bytes locally as the canonical attachment.
     */
    private function sendMediaByLink(
        Conversation $conversation,
        ?OutboundMedia $media,
        string $mediaType,
        MessageType $messageTypeEnum,
        string $publicSubdir,
        array $extraMediaFields,
        ?string $body,
        array $extraMeta = [],
    ): ?Message {
        $connection = $conversation->connection;

        if ($media === null) {
            throw new Exception("No media provided for WhatsApp {$mediaType}");
        }

        // URL fast-path
        if ($media->isUrl()) {
            try {
                return $this->postMediaLink(
                    $conversation,
                    $media->url,
                    $mediaType,
                    $messageTypeEnum,
                    $extraMediaFields,
                    $body,
                    $extraMeta,
                    externalAttachment: $media->url,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappOfficialHandler: URL media send failed, falling back to download', [
                    'media_type' => $mediaType,
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);

                $downloaded = $media->toUploadedFile();
                if (!$downloaded) {
                    throw new Exception("Failed to send WhatsApp {$mediaType} by URL and download fallback failed");
                }
                $media = OutboundMedia::fromFile($downloaded);
            }
        }

        // File path
        $uploadedFile = $media->file;
        $tempPublicPath = null;

        try {
            $content = file_get_contents($uploadedFile->getRealPath());
            $extension = $uploadedFile->getClientOriginalExtension() ?: $media->extension;

            $tempFileName = 'temp_' . uniqid() . '.' . $extension;
            $tempPublicPath = $publicSubdir . '/' . $tempFileName;
            Storage::disk('public')->put($tempPublicPath, $content);

            $publicUrl = url('storage/' . $tempPublicPath);

            $message = $this->postMediaLink(
                $conversation,
                $publicUrl,
                $mediaType,
                $messageTypeEnum,
                $extraMediaFields,
                $body,
                $extraMeta,
            );

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

    /**
     * POST a media `link` to the Cloud API and create the outgoing Message row.
     * Does not persist any bytes locally; when $externalAttachment is given it is
     * stored as the Message attachment (URL fast-path).
     */
    private function postMediaLink(
        Conversation $conversation,
        string $link,
        string $mediaType,
        MessageType $messageTypeEnum,
        array $extraMediaFields,
        ?string $body,
        array $extraMeta,
        ?string $externalAttachment = null,
    ): Message {
        $connection = $conversation->connection;

        $mediaPayload = array_filter(
            array_merge(['link' => $link], $extraMediaFields),
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

        if ($externalAttachment !== null) {
            $message->update(['attachment' => $externalAttachment]);
        }

        return $message;
    }
}
