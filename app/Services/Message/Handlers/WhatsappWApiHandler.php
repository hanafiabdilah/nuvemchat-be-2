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
            $imageBase64 = base64_encode(file_get_contents($data['image']->getRealPath()));

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-image?instanceId=' . $connection->credentials['instance_id'], [
                'phone' => $conversation->external_id,
                'image' => $imageBase64,
                'caption' => $data['message'] ?? null,
            ]);

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Image message sent', [
                'image' => $data['image']->getClientOriginalName(),
                'imageBase64Size' => strlen($imageBase64),
                'imageBase64' => $imageBase64,
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

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['image']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $imageBase64);

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
}
