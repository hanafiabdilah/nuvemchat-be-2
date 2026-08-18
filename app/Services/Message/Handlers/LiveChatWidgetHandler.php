<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\Widget\WidgetMessageReceived;
use App\Events\Widget\WidgetMessagesRead;
use App\Events\Widget\WidgetTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\Contracts\MarksMessagesAsRead;
use App\Services\Message\Contracts\SendsTypingIndicator;
use App\Services\Message\MessageHandlerInterface;
use App\Services\Message\OutboundMedia;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LiveChatWidgetHandler implements MessageHandlerInterface, SendsTypingIndicator, MarksMessagesAsRead
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
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'media_url' => 'required_without:image|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'image');
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Audio, [
            'audio' => 'required_without:media_url|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'media_url' => 'required_without:audio|url',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'audio');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Video, [
            'video' => 'required_without:media_url|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'media_url' => 'required_without:video|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'video');
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        return $this->handleSendMedia($conversation, $data, MessageType::Document, [
            'document' => 'required_without:media_url|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'media_url' => 'required_without:document|url',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ], 'document');
    }

    private function handleSendMedia(Conversation $conversation, array $data, MessageType $type, array $rules, string $fileKey): ?Message
    {
        validator($data, $rules)->validate();

        try {
            $now = Carbon::now();

            // URL fast-path: store the external URL as the attachment; the widget
            // fetches it directly, so no bytes are downloaded or persisted.
            $media = OutboundMedia::fromData($data, $fileKey);
            if ($media && $media->isUrl()) {
                $message = $conversation->messages()->create([
                    'external_id' => (string) Str::uuid(),
                    'sender_type' => SenderType::Outgoing,
                    'message_type' => $type,
                    'body' => $data['message'] ?? null,
                    'replied_message_id' => $data['replied_message_id'] ?? null,
                    'sent_at' => $now,
                    'delivery_at' => $now,
                    'attachment' => $media->url,
                    'meta' => [
                        'filename' => $media->filename,
                        'mime_type' => $media->mimeType,
                        'size' => null,
                    ],
                ]);

                broadcast(new WidgetMessageReceived($message->fresh()));

                return $message;
            }

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

    /**
     * The only channel here with no third party in the middle: the indicator is
     * our own event on the visitor's own session channel. The name sent is the
     * thread's assignee, not whoever happens to be typing — the widget draws it
     * beside an avatar it already chose from the assignee, and two different
     * names on one row would read as two different people.
     */
    public function handleTyping(Conversation $conversation, bool $typing = true): bool
    {
        broadcast(new WidgetTyping(
            $conversation->id,
            $typing,
            $conversation->agent?->name,
        ));

        return true;
    }

    /**
     * The one channel where the read receipt is entirely ours to deliver. The
     * widget already renders the second tick from each message's `read_at`, so
     * this only has to name the ids that changed and it turns them live —
     * nothing to store, since the rows were written before this ran.
     */
    public function handleMarkAsRead(Conversation $conversation, Collection $messages): bool
    {
        $ids = $messages->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return false;
        }

        broadcast(new WidgetMessagesRead(
            $conversation->id,
            $ids,
            Carbon::now()->toIso8601String(),
        ));

        return true;
    }
}
