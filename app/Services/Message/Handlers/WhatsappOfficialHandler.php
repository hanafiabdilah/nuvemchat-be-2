<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\Meta\GraphApi;
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
            $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
                ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $conversation->external_id,
                    'type' => 'text',
                    'text' => [
                        'body' => $data['message'],
                    ],
                ]));

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

    /**
     * Send a pre-approved WhatsApp message template. This is the sanctioned way
     * to open (or re-open) a conversation outside the 24-hour customer service
     * window. `components` is passed through to the Cloud API for variable /
     * header / button substitution.
     */
    public function handleSendTemplate(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'template_name' => 'required|string',
            'language' => 'required|string',
            'components' => 'nullable|array',
        ])->validate();

        $connection = $conversation->connection;

        $template = array_filter([
            'name' => $data['template_name'],
            'language' => ['code' => $data['language']],
            'components' => $data['components'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
                ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $conversation->external_id,
                    'type' => 'template',
                    'template' => $template,
                ]));

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to send template', [
                    'response_status' => $response->status(),
                    'response_body' => $responseArray,
                    'conversation_id' => $conversation->id,
                    'connection_id' => $connection->id,
                ]);

                throw new Exception($responseArray['error']['message'] ?? 'Failed to send WhatsApp template');
            }

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Template,
                'body' => $data['template_name'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, ['template' => $template]),
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to send template', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp template: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg|max:5120',
            'media_url' => 'required_without:image|url',
            'message' => 'nullable|string',
        ])->validate();

        return $this->sendMedia(
            $conversation,
            OutboundMedia::fromData($data, 'image'),
            'image',
            MessageType::Image,
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

        return $this->sendMedia(
            $conversation,
            OutboundMedia::fromData($data, 'audio'),
            'audio',
            MessageType::Audio,
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

        return $this->sendMedia(
            $conversation,
            OutboundMedia::fromData($data, 'video'),
            'video',
            MessageType::Video,
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

        return $this->sendMedia(
            $conversation,
            $media,
            'document',
            MessageType::Document,
            [
                'caption' => $data['message'] ?? null,
                'filename' => $filename,
            ],
            $data['message'] ?? null,
            ['filename' => $filename],
        );
    }

    /**
     * Mark the conversation's latest inbound message as read on WhatsApp Cloud
     * API (blue ticks for the customer). When $typing is true, also emit a
     * typing indicator — Cloud API bundles both into one call keyed by the
     * message id. Best-effort: returns false (never throws) so a read/typing
     * ping never breaks the caller.
     */
    public function handleMarkAsRead(Conversation $conversation, bool $typing = false): bool
    {
        $connection = $conversation->connection;
        $token = $connection->credentials['access_token'] ?? null;
        $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;

        if (!$token || !$phoneNumberId) {
            return false;
        }

        $lastInbound = $conversation->messages()
            ->where('sender_type', SenderType::Incoming)
            ->whereNotNull('external_id')
            ->latest('id')
            ->first();

        if (!$lastInbound) {
            return false;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $lastInbound->external_id,
        ];

        if ($typing) {
            $payload['typing_indicator'] = ['type' => 'text'];
        }

        try {
            $response = GraphApi::retry(fn () => Http::withToken($token)
                ->post(self::GRAPH_BASE . '/' . $phoneNumberId . '/messages', $payload));

            if (!$response->successful()) {
                Log::warning('WhatsappOfficialHandler: mark-as-read/typing failed', [
                    'conversation_id' => $conversation->id,
                    'typing' => $typing,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $th) {
            Log::warning('WhatsappOfficialHandler: mark-as-read/typing error', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);
            return false;
        }
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
     * Send a media message on WhatsApp Cloud API.
     *
     * URL fast-path: reference the caller's URL directly via `link` (Meta fetches
     * it), store the URL as the attachment, no bytes uploaded/persisted. If Meta
     * rejects the URL, fall back once to downloading the bytes.
     * File path: upload the bytes to the Cloud API media endpoint and send by
     * media `id` (no public-URL exposure), then persist the bytes locally as the
     * canonical attachment.
     */
    private function sendMedia(
        Conversation $conversation,
        ?OutboundMedia $media,
        string $mediaType,
        MessageType $messageTypeEnum,
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
                return $this->postMedia(
                    $conversation,
                    ['link' => $media->url],
                    $mediaType,
                    $messageTypeEnum,
                    $extraMediaFields,
                    $body,
                    $extraMeta,
                    externalAttachment: $media->url,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappOfficialHandler: URL media send failed, falling back to upload', [
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

        // File path: native upload → send by media id.
        $uploadedFile = $media->file;

        try {
            $content = file_get_contents($uploadedFile->getRealPath());
            $extension = $uploadedFile->getClientOriginalExtension() ?: $media->extension;
            $mimeType = $uploadedFile->getMimeType() ?: ($media->mimeType ?? 'application/octet-stream');
            $filename = $uploadedFile->getClientOriginalName() ?: ('file.' . $extension);

            $mediaId = $this->uploadMedia($connection, $content, $mimeType, $filename);

            $message = $this->postMedia(
                $conversation,
                ['id' => $mediaId],
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
     * Upload raw media bytes to the Cloud API media endpoint and return the
     * media id, so a message can reference it with {id} instead of exposing the
     * file at a public URL.
     */
    private function uploadMedia(Connection $connection, string $content, string $mimeType, string $filename): string
    {
        $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
            ->attach('file', $content, $filename, ['Content-Type' => $mimeType])
            ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/media', [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]));

        $json = $response->json();

        if (!$response->successful() || empty($json['id'])) {
            Log::error('WhatsappOfficialHandler: media upload failed', [
                'status' => $response->status(),
                'body' => $json,
            ]);

            throw new Exception($json['error']['message'] ?? 'Failed to upload media to WhatsApp');
        }

        return $json['id'];
    }

    /**
     * POST a media message (referenced by `link` or `id`) to the Cloud API and
     * create the outgoing Message row. Does not persist any bytes locally; when
     * $externalAttachment is given it is stored as the Message attachment.
     */
    private function postMedia(
        Conversation $conversation,
        array $mediaRef,
        string $mediaType,
        MessageType $messageTypeEnum,
        array $extraMediaFields,
        ?string $body,
        array $extraMeta,
        ?string $externalAttachment = null,
    ): Message {
        $connection = $conversation->connection;

        $mediaPayload = array_filter(
            array_merge($mediaRef, $extraMediaFields),
            fn($v) => $v !== null && $v !== '',
        );

        $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
            ->post(self::GRAPH_BASE . '/' . $connection->credentials['phone_number_id'] . '/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $conversation->external_id,
                'type' => $mediaType,
                $mediaType => $mediaPayload,
            ]));

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
