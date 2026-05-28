<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\Widget\WidgetMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LiveChatWidgetHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'] ?? (string) Str::uuid();
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['sent_at'])) {
            return Carbon::parse($payload['sent_at']);
        }

        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        try {
            $now = Carbon::now();

            $message = $conversation->messages()->create([
                'external_id' => (string) Str::uuid(),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $now,
                'delivery_at' => $now,
            ]);

            broadcast(new WidgetMessageReceived($message));

            return $message;
        } catch (\Throwable $th) {
            Log::error('LiveChatWidgetHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
            ]);

            throw new Exception('Failed to send Live Chat Widget message');
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Image, [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'image');
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Audio, [
            'audio' => 'required|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'audio');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Video, [
            'video' => 'required|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'video');
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Document, [
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'document');
    }

    private function handleSendMedia(Conversation $conversation, array $data, MessageType $type, array $rules, string $fileKey): ?Message
    {
        validator($data, $rules)->validate();

        try {
            $now = Carbon::now();
            $file = $data[$fileKey];

            $message = $conversation->messages()->create([
                'external_id' => (string) Str::uuid(),
                'sender_type' => SenderType::Outgoing,
                'message_type' => $type,
                'body' => $data['message'] ?? null,
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => $now,
                'delivery_at' => $now,
                'meta' => [
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ],
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, file_get_contents($file->getRealPath()));

            $message->update(['attachment' => $mediaPath]);

            broadcast(new WidgetMessageReceived($message->fresh()));

            return $message;
        } catch (\Throwable $th) {
            Log::error('LiveChatWidgetHandler: Failed to send media message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'type' => $type->value,
            ]);

            throw new Exception('Failed to send Live Chat Widget media message');
        }
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        if ($message->message_type !== MessageType::Text) {
            throw new Exception('Only text messages can be edited on Live Chat Widget');
        }

        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $message->update([
            'body' => $data['message'],
            'edited_at' => Carbon::now(),
        ]);

        broadcast(new WidgetMessageReceived($message->fresh()));

        return $message->fresh();
    }

    public function handleDeleteMessage(Message $message): bool
    {
        $message->update(['unsend_at' => Carbon::now()]);

        broadcast(new WidgetMessageReceived($message->fresh()));

        return true;
    }
}
