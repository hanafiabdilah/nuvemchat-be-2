<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Conversation;
use App\Services\Connection\TikTok\TikTokMessagingClient;
use App\Services\Connection\TikTok\TikTokReplyWindow;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TikTokHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'conversation_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        // TikTok can never initiate: the conversation must already exist
        // (created by an inbound webhook when the user messaged first).
        $conversation = Conversation::where('connection_id', $connection->id)
            ->where('external_id', $data['conversation_id'])
            ->orderByDesc('id')
            ->first();

        if (! $conversation) {
            throw ValidationException::withMessages([
                'conversation_id' => 'Unknown TikTok conversation. TikTok DMs can only reply to users who messaged you first.',
            ]);
        }

        if (! TikTokReplyWindow::isOpen($conversation)) {
            throw ValidationException::withMessages([
                'conversation_id' => 'The TikTok 48-hour reply window for this conversation has closed.',
            ]);
        }

        try {
            $client = new TikTokMessagingClient($connection);

            $messageId = $client->sendText($data['conversation_id'], $data['message']);

            Log::info('TikTokHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'conversation_id' => $data['conversation_id'],
                'message_id' => $messageId,
            ]);

            // Save immediately (following the other V1 handlers) — the webhook
            // echo that arrives later is deduped by external_id.
            $message = $conversation->messages()->updateOrCreate([
                'external_id' => $messageId ?: uniqid('tt_', true),
            ], [
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => now(),
                'delivery_at' => now(),
                'meta' => ['message_id' => $messageId],
            ]);

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            return ['message_id' => $messageId];
        } catch (\Throwable $th) {
            Log::error('TikTokHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send TikTok message: ' . $th->getMessage());
        }
    }
}
