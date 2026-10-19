<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Connection\Channel;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\TikTok\TikTokMessagingClient;
use App\Services\Connection\TikTok\TikTokReplyWindow;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TikTokHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        // Images only, besides text — OutgoingMessage refuses the rest.
        $outgoing = OutgoingMessage::fromData(Channel::TikTok, $data, [
            'conversation_id' => 'required|string',
        ]);

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

        // TikTok has no send-by-URL: the image is uploaded first, so it is
        // fetched here — before anything reaches TikTok.
        $file = $outgoing->isText() ? null : $outgoing->download();

        try {
            $client = new TikTokMessagingClient($connection);

            $mediaId = null;
            $contents = null;

            if ($file === null) {
                $messageId = $client->sendText($data['conversation_id'], $outgoing->text);
            } else {
                $contents = file_get_contents($file->getRealPath());
                $mediaId = $client->uploadMedia(
                    $contents,
                    $file->getClientOriginalName() ?: 'image.jpg',
                    $file->getMimeType() ?: 'image/jpeg',
                );
                $messageId = $client->sendImage($data['conversation_id'], $mediaId);
            }

            Log::info('TikTokHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'conversation_id' => $data['conversation_id'],
                'type' => $outgoing->type,
                'message_id' => $messageId,
            ]);

            // Save immediately (following the other V1 handlers) — the webhook
            // echo that arrives later is deduped by external_id.
            $message = $conversation->messages()->updateOrCreate([
                'external_id' => $messageId ?: uniqid('tt_', true),
            ], [
                'sender_type' => SenderType::Outgoing,
                'message_type' => $outgoing->messageType(),
                'body' => $outgoing->isText() ? $outgoing->text : null,
                'sent_at' => now(),
                'delivery_at' => now(),
                'meta' => array_filter(['message_id' => $messageId, 'media_id' => $mediaId]),
            ]);

            if ($file !== null) {
                $this->keepCopy($message, $file, $contents);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            return ['message_id' => $messageId] + ($mediaId ? ['media_id' => $mediaId] : []);
        } catch (\Throwable $th) {
            Log::error('TikTokHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send TikTok message: ' . $th->getMessage(), 0, $th);
        } finally {
            if ($file !== null) {
                @unlink($file->getRealPath());
            }
        }
    }

    /**
     * The bytes are already in hand, so the thread keeps its own copy — the
     * same as an image the dashboard sends here — rather than a pointer to the
     * caller's URL, which may stop working.
     */
    private function keepCopy(Message $message, UploadedFile $file, string $contents): void
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = MediaFilename::path('media', (string) $message->id, $file->getClientOriginalName(), $extension);

        MediaStorage::disk()->put($path, $contents);
        $message->update(['attachment' => $path]);
    }
}
