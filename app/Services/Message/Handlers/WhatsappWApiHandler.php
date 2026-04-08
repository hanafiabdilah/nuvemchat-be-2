<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsappWApiHandler implements MessageHandlerInterface
{
    public function getConversationId(array $payload): string
    {
        return $payload['chat']['id'] ?? null;
    }

    public function getMessageId(array $payload): string
    {
        return $payload['messageId'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now(); // Assuming message is sent now
    }


    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        // For new conversation
        if(is_null($conversation->external_id)) {
            $conversation->update([
                'external_id' => $conversation->id,
            ]);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-text?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'message' => $data['message'],
            ]);

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
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'message' => 'nullable|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            // Get image content and encode to base64
            $imageContent = file_get_contents($data['image']->getRealPath());
            $imageBase64 = base64_encode($imageContent);

            // Get mime type and create data URI format
            $mimeType = $data['image']->getMimeType();
            $imageDataUri = 'data:' . $mimeType . ';base64,' . $imageBase64;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-image?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'image' => $imageDataUri,
                'caption' => $data['message'] ?? null,
            ]);

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
            'audio' => 'required|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
        ])->validate();

        $connection = $conversation->connection;

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

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-audio?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'audio' => $audioDataUri,
            ]);

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
            'video' => 'required|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'message' => 'nullable|string',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

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

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-video?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'video' => $videoUrl,
                'caption' => $data['message'] ?? null,
            ]);

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
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'message' => 'nullable|string',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

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

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-document?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'document' => $documentUrl,
                'fileName' => $filename,
                'extension' => $extension,
                'caption' => $data['message'] ?? null,
            ]);

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
}
