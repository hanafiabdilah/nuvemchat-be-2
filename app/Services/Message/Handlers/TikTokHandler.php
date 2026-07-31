<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\ConnectionException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\TikTok\TikTokMessagingClient;
use App\Services\Connection\TikTok\TikTokReplyWindow;
use App\Services\Message\MessageHandlerInterface;
use App\Services\Message\OutboundMedia;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Outbound TikTok DMs from the agent UI. The Business Messaging API only
 * supports TEXT and IMAGE messages, always addressed to an existing
 * conversation, and only within the 48h reply window.
 */
class TikTokHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'] ?? uniqid('tt_', true);
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

        TikTokReplyWindow::assertOpen($conversation);

        try {
            $client = new TikTokMessagingClient($conversation->connection);

            $repliedMessageExternalId = $this->getRepliedMessageExternalId($conversation, $data['replied_message_id'] ?? null);

            $messageId = $client->sendText($conversation->external_id, $data['message'], $repliedMessageExternalId);

            return $conversation->messages()->create([
                'external_id' => $messageId ?: $this->getMessageId([]),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'replied_message_id' => $data['replied_message_id'] ?? null,
                'sent_at' => now(),
                'delivery_at' => now(),
                'meta' => ['message_id' => $messageId],
            ]);
        } catch (ConnectionException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('TikTokHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
            ]);

            throw new Exception('Failed to send TikTok message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'image' => 'required_without:media_url|image|mimes:jpeg,png,jpg,gif,webp|max:8192',
            'media_url' => 'required_without:image|url',
        ])->validate();

        TikTokReplyWindow::assertOpen($conversation);

        try {
            // TikTok has no send-by-URL: bytes are uploaded first, so a
            // media_url is downloaded into an UploadedFile before uploading.
            $media = OutboundMedia::fromData($data, 'image');
            $file = $media?->file ?? $media?->toUploadedFile();

            if (! $file) {
                throw new Exception('Could not resolve the image to send');
            }

            $contents = file_get_contents($file->getRealPath());
            $extension = $file->getClientOriginalExtension() ?: 'jpg';

            $client = new TikTokMessagingClient($conversation->connection);

            $mediaId = $client->uploadMedia(
                $contents,
                $file->getClientOriginalName() ?: 'image.' . $extension,
                $file->getMimeType() ?: 'image/jpeg'
            );

            $messageId = $client->sendImage($conversation->external_id, $mediaId);

            $message = $conversation->messages()->create([
                'external_id' => $messageId ?: $this->getMessageId([]),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Image,
                'body' => null,
                'sent_at' => now(),
                'delivery_at' => now(),
                'meta' => ['message_id' => $messageId, 'media_id' => $mediaId],
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $contents);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            return $message;
        } catch (ConnectionException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('TikTokHandler: Failed to send image', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
            ]);

            throw new Exception('Failed to send TikTok image: ' . $th->getMessage());
        }
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('TikTok Business Messaging only supports text and image messages');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('TikTok Business Messaging only supports text and image messages');
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('TikTok Business Messaging only supports text and image messages');
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        throw new Exception('Message editing not supported by TikTok Business Messaging');
    }

    public function handleDeleteMessage(Message $message): bool
    {
        throw new Exception('Message deletion not supported by TikTok Business Messaging');
    }

    /**
     * Resolve a local replied_message_id into TikTok's message id so the
     * reply is threaded on the user's side too.
     */
    private function getRepliedMessageExternalId(Conversation $conversation, ?int $repliedMessageId): ?string
    {
        if (! $repliedMessageId) {
            return null;
        }

        return Message::where('id', $repliedMessageId)
            ->where('conversation_id', $conversation->id)
            ->value('external_id');
    }
}
