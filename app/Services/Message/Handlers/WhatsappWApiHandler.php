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

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-text?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'message' => $data['message'],
            ]);

            $responseArray = $response->json();

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
            // Get audio content and encode to base64
            $audioContent = file_get_contents($data['audio']->getRealPath());
            $audioBase64 = base64_encode($audioContent);

            // Get mime type and create data URI format
            $mimeType = $data['audio']->getMimeType();
            $audioDataUri = 'data:' . $mimeType . ';base64,' . $audioBase64;

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

            // Store the original audio content
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['audio']->getClientOriginalExtension();
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
}
