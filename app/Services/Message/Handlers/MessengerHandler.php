<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
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

/**
 * Outbound Messenger sends via the Page Send API. Same URL-attachment model as
 * Instagram, but against graph.facebook.com with the Page access token, and
 * with messaging_type=RESPONSE (we only ever reply inside the 24h window).
 */
class MessengerHandler implements MessageHandlerInterface
{
    private const MESSAGES_URL = 'https://graph.facebook.com/v25.0/me/messages';

    public function getMessageId(array $payload): string
    {
        return $payload['message_id'] ?? uniqid('fbm_', true);
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
                ->post(self::MESSAGES_URL, [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'messaging_type' => 'RESPONSE',
                    'message' => [
                        'text' => $data['message'],
                    ],
                ]));

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('MessengerHandler: Failed to send message', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Messenger message');
            }

            return $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Messenger message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif|max:8192',
            'media_url' => 'required_without:image|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'image', 'image', MessageType::Image);
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'audio' => 'required_without:media_url|file|mimes:aac,m4a,wav,mp4,mp3,ogg,opus,webm|max:25600',
            'media_url' => 'required_without:audio|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'audio', 'audio', MessageType::Audio);
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'video' => 'required_without:media_url|file|mimes:mp4,ogg,avi,mov,webm|max:25600',
            'media_url' => 'required_without:video|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'video', 'video', MessageType::Video);
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'document' => 'required_without:media_url|file|mimes:pdf,doc,docx,xls,xlsx,txt,zip|max:25600',
            'media_url' => 'required_without:document|url',
        ])->validate();

        return $this->handleSendMedia($conversation, $data, 'document', 'file', MessageType::Document);
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        throw new Exception('Message editing not supported for Messenger API');
    }

    public function handleDeleteMessage(Message $message): bool
    {
        throw new Exception('Message deletion not supported for Messenger API');
    }

    /**
     * Shared media pipeline: URL fast-path first; uploaded files are hosted on
     * the public disk so Messenger can fetch them, while the original bytes are
     * kept on the local disk for the dashboard attachment preview.
     */
    private function handleSendMedia(
        Conversation $conversation,
        array $data,
        string $fileKey,
        string $fbType,
        MessageType $messageTypeEnum,
    ): ?Message {
        $connection = $conversation->connection;
        $tempPublicPath = null;

        $media = OutboundMedia::fromData($data, $fileKey);
        if ($media && $media->isUrl()) {
            try {
                return $this->sendAttachmentByUrl($conversation, $media->url, $fbType, $messageTypeEnum, $fileKey === 'document' ? ['filename' => $media->filename] : []);
            } catch (\Throwable $th) {
                Log::warning("MessengerHandler: URL {$fbType} send failed, falling back to download", [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception("Failed to send Messenger {$fbType} by URL and download fallback failed");
                }
                $data[$fileKey] = $file;
            }
        }

        try {
            $content = file_get_contents($data[$fileKey]->getRealPath());
            $extension = $data[$fileKey]->getClientOriginalExtension();
            $tempPublicPath = $fbType . 's/temp_' . uniqid() . '.' . $extension;

            Storage::disk('public')->put($tempPublicPath, $content);

            $publicUrl = url('storage/' . $tempPublicPath);

            $extraMeta = $fileKey === 'document'
                ? ['filename' => $data[$fileKey]->getClientOriginalName()]
                : [];

            $message = $this->sendAttachmentByUrl($conversation, $publicUrl, $fbType, $messageTypeEnum, $extraMeta);

            // Keep the original bytes privately for the dashboard preview.
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $content);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            Log::error("MessengerHandler: Failed to send {$fbType}", [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception("Failed to send Messenger {$fbType}: " . $th->getMessage());
        }
    }

    private function sendAttachmentByUrl(
        Conversation $conversation,
        string $url,
        string $fbType,
        MessageType $messageTypeEnum,
        array $extraMeta = [],
    ): Message {
        $connection = $conversation->connection;

        $response = GraphApi::retry(fn () => Http::withToken($connection->credentials['access_token'])
            ->post(self::MESSAGES_URL, [
                'recipient' => [
                    'id' => $conversation->external_id,
                ],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'attachment' => [
                        'type' => $fbType,
                        'payload' => [
                            'url' => $url,
                            'is_reusable' => true,
                        ],
                    ],
                ],
            ]));

        $responseArray = $response->json();

        if (!$response->successful()) {
            throw new Exception($responseArray['error']['message'] ?? "Failed to send Messenger {$fbType}");
        }

        $message = $conversation->messages()->create([
            'external_id' => $this->getMessageId($responseArray),
            'sender_type' => SenderType::Outgoing,
            'message_type' => $messageTypeEnum,
            'body' => null,
            'sent_at' => $this->getMessageSentAt($responseArray),
            'delivery_at' => $this->getMessageSentAt($responseArray),
            'meta' => array_merge($responseArray, $extraMeta),
        ]);

        $message->update(['attachment' => $url]);

        return $message;
    }
}
