<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use App\Services\Message\OutboundMedia;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsappWApiHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['messageId'];
    }

    /**
     * W-API base URL. Overridden by the API Way handler.
     */
    protected function base(): string
    {
        return 'https://api.w-api.app/v1';
    }

    /**
     * URL fast-path: the W-API send-{type} endpoints accept a URL directly in
     * the media field, so reference the caller's URL, store it as the attachment
     * and skip hosting/persisting bytes. Throws on a non-success response so the
     * caller can fall back to downloading the file.
     */
    protected function sendMediaByUrl(
        Conversation $conversation,
        array $data,
        string $fieldKey,
        string $endpointType,
        MessageType $messageTypeEnum,
        string $url,
        array $extraPayload = [],
        array $extraMeta = [],
        ?string $body = null,
    ): Message {
        $connection = $conversation->connection;
        $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

        $payload = array_merge([
            'phone' => $conversation->external_id,
            $fieldKey => $url,
        ], $extraPayload);

        if ($repliedMessageExternalId) {
            $payload['messageId'] = $repliedMessageExternalId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->post($this->base() . '/message/send-' . $endpointType . '?instanceId=' . $connection->credentials['instance_id'], $payload);

        if (!$response->successful()) {
            throw new Exception("W-API rejected {$endpointType} URL: " . $response->body());
        }

        $responseArray = $response->json();

        $message = $conversation->messages()->create([
            'external_id' => $responseArray['messageId'] ?? uniqid('wapi_', true),
            'sender_type' => SenderType::Outgoing,
            'message_type' => $messageTypeEnum,
            'body' => $body,
            'replied_message_id' => $data['replied_message_id'] ?? null,
            'sent_at' => $this->getMessageSentAt($responseArray),
            'meta' => array_merge($responseArray, $extraMeta),
        ]);

        $message->update(['attachment' => $url]);

        return $message;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now(); // Assuming message is sent now
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
            Log::warning('WhatsappWApiHandler: Replied message not found', [
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
            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'phone' => $conversation->external_id,
                'message' => $data['message'],
            ];

            if ($repliedMessageExternalId) {
                $payload['messageId'] = $repliedMessageExternalId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-text?instanceId=' . $connection->credentials['instance_id'], $payload);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Text message sent', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message');
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'media_url' => 'required_without:image|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'image');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl(
                    $conversation,
                    $data,
                    'image',
                    'image',
                    MessageType::Image,
                    $media->url,
                    ['caption' => $data['message'] ?? null],
                    [],
                    $data['message'] ?? null,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappWApiHandler: URL image send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send WhatsApp image by URL and download fallback failed');
                }
                $data['image'] = $file;
            }
        }

        try {
            // Get image content and encode to base64
            $imageContent = file_get_contents($data['image']->getRealPath());
            $imageBase64 = base64_encode($imageContent);

            // Get mime type and create data URI format
            $mimeType = $data['image']->getMimeType();
            $imageDataUri = 'data:' . $mimeType . ';base64,' . $imageBase64;

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'phone' => $conversation->external_id,
                'image' => $imageDataUri,
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['messageId'] = $repliedMessageExternalId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-image?instanceId=' . $connection->credentials['instance_id'], $payload);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Image message sent', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Image,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            // Store the original image content (not base64)
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['image']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $imageContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to send image message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp image message');
        }
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'audio' => 'required_without:media_url|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'media_url' => 'required_without:audio|url',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;

        $media = OutboundMedia::fromData($data, 'audio');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl(
                    $conversation,
                    $data,
                    'audio',
                    'audio',
                    MessageType::Audio,
                    $media->url,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappWApiHandler: URL audio send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send WhatsApp audio by URL and download fallback failed');
                }
                $data['audio'] = $file;
            }
        }

        try {
            $audioContent = file_get_contents($data['audio']->getRealPath());
            $extension = strtolower($data['audio']->getClientOriginalExtension());

            // Convert to OGG if not MP3 or OGG
            if (!in_array($extension, ['mp3', 'ogg'])) {
                Log::info('WhatsappWApiHandler: Converting audio to OGG', [
                    'original_format' => $extension,
                ]);

                $inputPath = $data['audio']->getRealPath();
                $outputPath = sys_get_temp_dir() . '/' . uniqid() . '.ogg';

                // Convert using FFmpeg
                $command = sprintf(
                    'ffmpeg -i %s -c:a libopus -b:a 32k %s 2>&1',
                    escapeshellarg($inputPath),
                    escapeshellarg($outputPath)
                );

                exec($command, $output, $returnVar);

                if ($returnVar !== 0 || !file_exists($outputPath)) {
                    Log::error('WhatsappWApiHandler: Audio conversion failed', [
                        'command' => $command,
                        'output' => $output,
                        'return_var' => $returnVar,
                    ]);
                    throw new Exception('Failed to convert audio format');
                }

                $audioContent = file_get_contents($outputPath);
                $extension = 'ogg';
                $mimeType = 'audio/ogg';

                // Clean up temp file
                @unlink($outputPath);
            } else {
                // Use original for MP3/OGG
                $mimeType = $extension === 'mp3' ? 'audio/mpeg' : 'audio/ogg';
            }

            // Encode to base64 with data URI
            $audioBase64 = base64_encode($audioContent);
            $audioDataUri = 'data:' . $mimeType . ';base64,' . $audioBase64;

            Log::info('WhatsappWApiHandler: Sending audio', [
                'format' => $extension,
                'mime_type' => $mimeType,
                'size' => strlen($audioContent),
            ]);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'phone' => $conversation->external_id,
                'audio' => $audioDataUri,
            ];

            if ($repliedMessageExternalId) {
                $payload['messageId'] = $repliedMessageExternalId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-audio?instanceId=' . $connection->credentials['instance_id'], $payload);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Audio message sent', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Audio,
                'body' => null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            // Store the converted audio content
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $audioContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to send audio message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp audio message');
        }
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'video' => 'required_without:media_url|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'media_url' => 'required_without:video|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

        $media = OutboundMedia::fromData($data, 'video');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl(
                    $conversation,
                    $data,
                    'video',
                    'video',
                    MessageType::Video,
                    $media->url,
                    ['caption' => $data['message'] ?? null],
                    [],
                    $data['message'] ?? null,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappWApiHandler: URL video send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send WhatsApp video by URL and download fallback failed');
                }
                $data['video'] = $file;
            }
        }

        try {
            // Store video temporarily in public directory
            $videoContent = file_get_contents($data['video']->getRealPath());
            $tempFileName = 'temp_' . uniqid() . '.' . $data['video']->getClientOriginalExtension();
            $tempPublicPath = 'videos/' . $tempFileName;

            Storage::disk('public')->put($tempPublicPath, $videoContent);

            // Generate public URL
            $videoUrl = url('storage/' . $tempPublicPath);

            Log::info('WhatsappWApiHandler: Sending video via URL', [
                'url' => $videoUrl,
                'size' => strlen($videoContent),
                'conversation_id' => $conversation->id,
            ]);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'phone' => $conversation->external_id,
                'video' => $videoUrl,
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['messageId'] = $repliedMessageExternalId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-video?instanceId=' . $connection->credentials['instance_id'], $payload);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Video message sent', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Video,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            // Store the original video content permanently
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['video']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $videoContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary file if exists
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            Log::error('WhatsappWApiHandler: Failed to send video message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp video message');
        }
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'document' => 'required_without:media_url|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'media_url' => 'required_without:document|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

        $media = OutboundMedia::fromData($data, 'document');
        if ($media && $media->isUrl()) {
            try {
                return $this->sendMediaByUrl(
                    $conversation,
                    $data,
                    'document',
                    'document',
                    MessageType::Document,
                    $media->url,
                    [
                        'fileName' => $media->filename,
                        'extension' => $media->extension,
                        'caption' => $data['message'] ?? null,
                    ],
                    ['filename' => $media->filename],
                    $data['message'] ?? null,
                );
            } catch (\Throwable $th) {
                Log::warning('WhatsappWApiHandler: URL document send failed, falling back to download', [
                    'error' => $th->getMessage(),
                    'conversation_id' => $conversation->id,
                ]);
                $file = $media->toUploadedFile();
                if (!$file) {
                    throw new Exception('Failed to send WhatsApp document by URL and download fallback failed');
                }
                $data['document'] = $file;
            }
        }

        try {
            // Store document temporarily in public directory
            $documentContent = file_get_contents($data['document']->getRealPath());
            $tempFileName = 'temp_' . uniqid() . '.' . $data['document']->getClientOriginalExtension();
            $tempPublicPath = 'documents/' . $tempFileName;

            Storage::disk('public')->put($tempPublicPath, $documentContent);

            // Generate public URL
            $documentUrl = url('storage/' . $tempPublicPath);

            // Get original filename and extension
            $filename = $data['document']->getClientOriginalName();
            $extension = $data['document']->getClientOriginalExtension();

            Log::info('WhatsappWApiHandler: Sending document via URL', [
                'url' => $documentUrl,
                'filename' => $filename,
                'extension' => $extension,
                'size' => strlen($documentContent),
                'conversation_id' => $conversation->id,
            ]);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $payload = [
                'phone' => $conversation->external_id,
                'document' => $documentUrl,
                'fileName' => $filename,
                'extension' => $extension,
                'caption' => $data['message'] ?? null,
            ];

            if ($repliedMessageExternalId) {
                $payload['messageId'] = $repliedMessageExternalId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-document?instanceId=' . $connection->credentials['instance_id'], $payload);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Document message sent', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Document,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, ['filename' => $filename]),
            ]);

            // Store the original document content permanently
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['document']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $documentContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary file if exists
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            Log::error('WhatsappWApiHandler: Failed to send document message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp document message');
        }
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        // WhatsApp hanya support edit text message
        if ($message->message_type !== MessageType::Text) {
            throw new Exception('Only text messages can be edited on WhatsApp');
        }

        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            // W-API endpoint untuk edit message
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/edit-message?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'messageId' => $message->external_id,
                'text' => $data['message'],
            ]);

            $responseArray = $response->json();

            // Cek jika API tidak support edit message
            if (!$response->successful()) {
                Log::warning('WhatsappWApiHandler: Edit message may not be supported', [
                    'response' => $responseArray,
                    'status' => $response->status(),
                    'message_id' => $message->id,
                ]);

                throw new Exception('WhatsApp W-API does not support message editing or request failed: ' .
                    ($responseArray['message'] ?? 'Unknown error'));
            }

            Log::info('WhatsappWApiHandler: Message edited successfully', [
                'response' => $responseArray,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            // Update message di database
            $message->update([
                'body' => $data['message'],
                'edited_at' => Carbon::now(),
            ]);

            return $message->fresh();
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to edit message', [
                'error' => $th->getMessage(),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to edit WhatsApp message: ' . $th->getMessage());
        }
    }

    public function handleDeleteMessage(Message $message): bool
    {
        $conversation = $message->conversation;
        $connection = $conversation->connection;

        try {
            // W-API menggunakan query parameters, bukan body
            $url = 'https://api.w-api.app/v1/message/delete-message?' . http_build_query([
                'instanceId' => $connection->credentials['instance_id'],
                'phone' => $conversation->external_id,
                'messageId' => $message->external_id,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->delete($url);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Delete response received', [
                'status' => $response->status(),
                'response' => $responseArray,
            ]);

            if (!$response->successful()) {
                Log::warning('WhatsappWApiHandler: Delete message request failed', [
                    'response' => $responseArray,
                    'status' => $response->status(),
                    'message_id' => $message->id,
                ]);

                throw new Exception('WhatsApp W-API delete message failed: ' .
                    ($responseArray['message'] ?? 'Unknown error'));
            }

            Log::info('WhatsappWApiHandler: Message deleted successfully', [
                'response' => $responseArray,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            $message->update([
                'unsend_at' => Carbon::now(),
            ]);

            return true;
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to delete message', [
                'error' => $th->getMessage(),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to delete WhatsApp message: ' . $th->getMessage());
        }
    }
}
