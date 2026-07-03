<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'chat_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $telegram = new Api($connection->credentials['token']);

            $response = $telegram->sendMessage([
                'chat_id' => $data['chat_id'],
                'text' => $data['message'],
            ]);

            $responseArray = $response->toArray();

            Log::info('TelegramHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'chat_id' => $data['chat_id'],
                'message_id' => $responseArray['message_id'] ?? null,
            ]);

            // Save message to database (following webhook pattern)
            $this->saveOutgoingMessage($connection, $responseArray);

            return $responseArray;
        } catch (TelegramSDKException $e) {
            Log::error('TelegramHandler: Telegram SDK error', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new TelegramSDKException($e->getMessage());
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram message: ' . $th->getMessage());
        }
    }

    private function saveOutgoingMessage(Connection $connection, array $responseArray): void
    {
        try {
            $messageId = $responseArray['message_id'] ?? null;
            $conversationId = $responseArray['chat']['id'] ?? null;
            $contactExternalId = $responseArray['chat']['id'] ?? null;
            $messageText = $responseArray['text'] ?? null;
            $sentAt = isset($responseArray['date']) ? Carbon::createFromTimestamp($responseArray['date']) : Carbon::now();

            if (!$messageId || !$conversationId || !$contactExternalId) {
                Log::warning('TelegramHandler: Missing required data in response', [
                    'message_id' => $messageId,
                    'conversation_id' => $conversationId,
                    'contact_external_id' => $contactExternalId,
                ]);
                return;
            }

            // Build contact name
            $contactName = '';
            if (isset($responseArray['chat']['first_name'])) {
                $contactName = $responseArray['chat']['first_name'];
                if (isset($responseArray['chat']['last_name'])) {
                    $contactName .= ' ' . $responseArray['chat']['last_name'];
                }
            }
            $contactUsername = $responseArray['chat']['username'] ?? null;

            $message = DB::transaction(function() use ($connection, $conversationId, $messageId, $messageText, $sentAt, $contactExternalId, $contactName, $contactUsername, $responseArray) {
                // Create or find contact
                $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

                // Find or create conversation
                $conversation = Conversation::where('external_id', $conversationId)
                    ->where('contact_id', $contact->id)
                    ->where('connection_id', $connection->id)
                    ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
                    ->first();

                if (!$conversation) {
                    $conversation = Conversation::create([
                        'contact_id'    => $contact->id,
                        'connection_id' => $connection->id,
                        'external_id'   => $conversationId,
                        'status'        => Status::Pending,
                    ]);
                }

                // Create outgoing message
                return $conversation->messages()->updateOrCreate([
                    'external_id' => (string) $messageId,
                ], [
                    'sender_type' => SenderType::Outgoing,
                    'message_type' => MessageType::Text,
                    'body' => $messageText,
                    'sent_at' => $sentAt,
                    'delivery_at' => $sentAt,
                    'meta' => $responseArray,
                ]);
            });

            if ($message) {
                broadcast(new MessageReceived($message));
                broadcast(new ConversationUpdated($message->conversation->load('contact')));
            }
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to save outgoing message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);
            // Don't throw - message was sent successfully, just log the save error
        }
    }
}
