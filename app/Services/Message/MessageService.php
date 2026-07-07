<?php

namespace App\Services\Message;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\Handlers\WhatsappOfficialHandler;

class MessageService
{
    public function sendMessage(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendMessage($conversation, $data);
    }

    /**
     * Send a WhatsApp message template. Templates are a WhatsApp Official (Cloud
     * API) concept only, so this rejects any other channel rather than adding a
     * no-op to every handler.
     */
    public function sendTemplate(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);

        if (!$handler instanceof WhatsappOfficialHandler) {
            throw new \RuntimeException('Message templates are only supported on WhatsApp Official connections');
        }

        return $handler->handleSendTemplate($conversation, $data);
    }

    public function sendInteractive(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);

        if (!$handler instanceof WhatsappOfficialHandler) {
            throw new \RuntimeException('Interactive messages are only supported on WhatsApp Official connections');
        }

        return $handler->handleSendInteractive($conversation, $data);
    }

    /**
     * Mark the latest inbound message as read (and optionally emit a typing
     * indicator) on the channel. Only WhatsApp Official supports Cloud read
     * receipts / typing; other channels are a silent no-op.
     */
    public function markAsRead(Conversation $conversation, bool $typing = false): bool
    {
        $handler = MessageFactory::make($conversation->connection->channel, []);

        if (!$handler instanceof WhatsappOfficialHandler) {
            return false;
        }

        return $handler->handleMarkAsRead($conversation, $typing);
    }

    public function sendImage(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendImage($conversation, $data);
    }

    public function sendAudio(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendAudio($conversation, $data);
    }

    public function sendVideo(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendVideo($conversation, $data);
    }

    public function sendDocument(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendDocument($conversation, $data);
    }

    public function editMessage(Message $message, array $data): ?Message
    {
        $handler = MessageFactory::make($message->conversation->connection->channel, $data);
        return $handler->handleEditMessage($message, $data);
    }

    public function deleteMessage(Message $message): bool
    {
        $handler = MessageFactory::make($message->conversation->connection->channel, []);
        return $handler->handleDeleteMessage($message);
    }
}
