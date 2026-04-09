<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AutomatedMessageService;
use App\Services\Message\MessageService;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InstagramHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        // For echo messages (outgoing), use recipient's ID as conversation identifier
        // For incoming messages, use sender's ID
        $messaging = $payload['messaging'][0] ?? [];
        $isEcho = $messaging['message']['is_echo'] ?? false;

        if ($isEcho) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['messaging'][0]['message']['mid'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $message = $payload['messaging'][0]['message'] ?? [];

        // Text message
        if (isset($message['text'])) {
            return $message['text'];
        }

        // Check for share/post attachments
        if (isset($message['attachments'][0])) {
            $attachment = $message['attachments'][0];
            $type = $attachment['type'] ?? null;

            // Instagram share, post, or reel
            if (in_array($type, ['share', 'ig_post', 'ig_reel'])) {
                $payload = $attachment['payload'] ?? [];
                $title = $payload['title'] ?? null;

                // Return title only (URL will be added after download)
                return $title ?? 'Instagram post shared';
            }

            // Media with caption
            if (isset($attachment['payload']['caption'])) {
                return $attachment['payload']['caption'];
            }
        }

        return null;
    }

    public function getMessageType(array $payload): MessageType
    {
        $message = $payload['messaging'][0]['message'] ?? [];

        // Check if it's a text message
        if (isset($message['text'])) {
            return MessageType::Text;
        }

        // Check attachments
        if (isset($message['attachments'][0]['type'])) {
            return match($message['attachments'][0]['type']) {
                'image' => MessageType::Image,
                'video' => MessageType::Video,
                'audio' => MessageType::Audio,
                'file' => MessageType::Document,
                'share', 'ig_post', 'ig_reel' => MessageType::Text,
                default => MessageType::Unsupported,
            };
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        $timestamp = $payload['messaging'][0]['timestamp'] ?? null;

        if ($timestamp) {
            return Carbon::createFromTimestampMs($timestamp);
        }

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        // Instagram doesn't provide name in webhook, will need to fetch via API
        $senderId = $payload['messaging'][0]['sender']['id'] ?? null;

        return $senderId; // Use ID as temporary name, will be updated later
    }

    public function getContactUsername(array $payload): ?string
    {
        // Instagram doesn't provide username in webhook
        return null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        // For echo messages (outgoing), contact is the recipient
        // For incoming messages, contact is the sender
        $messaging = $payload['messaging'][0] ?? [];
        $isEcho = $messaging['message']['is_echo'] ?? false;

        if ($isEcho) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    public function isOutgoingMessage(array $payload): bool
    {
        $messaging = $payload['messaging'][0] ?? [];
        return $messaging['message']['is_echo'] ?? false;
    }

    public function handle(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];

        // Determine event type
        $eventType = match (true) {
            isset($messaging['message']) => 'message',
            isset($messaging['read']) => 'read',
            default => 'unsupported',
        };

        // Handle based on event type
        switch ($eventType) {
            case 'message':
                $this->handleReceived($connection, $payload);
                break;

            case 'read':
                $this->handleStatus($connection, $payload);
                break;

            default:
                Log::warning('InstagramHandler: Unsupported event type', [
                    'payload' => $payload,
                ]);
                break;
        }
    }

    private function handleReceived(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);
        $isOutgoing = $this->isOutgoingMessage($payload);

        if (!$conversationId || !$messageId || !$contactExternalId){
            Log::warning('InstagramHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
            ]);
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername, $isOutgoing, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

            // Fetch Instagram user info to get name and username
            if($contact->wasRecentlyCreated) {
                $this->updateContactInfo($contact, $connection, $contactExternalId);
            }

            $conversation = Conversation::where('external_id', $conversationId)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [Status::Active, Status::Pending])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'contact_id'    => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id'   => $conversationId,
                    'status'        => Status::Pending,
                ]);
                $isNewConversation = true;
                $conversationForWelcome = $conversation;
            }

            if($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) return;

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => $isOutgoing ? SenderType::Outgoing : SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            // Handle media messages (including ephemeral)
            if(in_array($messageType, [MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Audio])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            // Handle Instagram share/post/reel to fetch permalink
            $messaging = $payload['messaging'][0] ?? [];
            $messageData = $messaging['message'] ?? [];
            if (isset($messageData['attachments'][0])) {
                $attachment = $messageData['attachments'][0];
                $attachmentType = $attachment['type'] ?? null;

                if (in_array($attachmentType, ['share', 'ig_post', 'ig_reel'])) {
                    $this->handleInstagramShare($message, $attachment, $connection);
                }
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            // Send welcoming message AFTER broadcasting the main message
            // Only send welcome message for incoming messages, not outgoing
            if ($isNewConversation && $conversationForWelcome && !$isOutgoing) {
                $automatedMessageService = new AutomatedMessageService();
                $welcomingMessage = $automatedMessageService->getWelcomingMessage($connection);

                if ($welcomingMessage) {
                    try {
                        $messageService = new MessageService();
                        $welcomeMsg = $messageService->sendMessage($conversationForWelcome, ['message' => $welcomingMessage]);

                        if ($welcomeMsg) {
                            broadcast(new MessageReceived($welcomeMsg));
                            broadcast(new ConversationUpdated($welcomeMsg->conversation));
                        }
                    } catch (\Throwable $th) {
                        Log::error('InstagramHandler: Failed to send welcoming message', [
                            'conversation_id' => $conversationForWelcome->id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    private function handleStatus(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $read = $messaging['read'] ?? [];
        $messageId = $read['mid'] ?? null;
        $timestamp = $messaging['timestamp'] ?? null;

        if (!$messageId) {
            Log::warning('InstagramHandler: Missing message ID in read status payload', [
                'payload' => $payload,
            ]);
            return;
        }

        try {
            // Find the message and update read status
            $message = Message::whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if ($message) {
                $readAt = $timestamp ? Carbon::createFromTimestampMs($timestamp) : Carbon::now();

                $message->update([
                    'read_at' => $readAt,
                ]);

                Log::info('InstagramHandler: Message marked as read', [
                    'message_id' => $message->id,
                    'external_id' => $messageId,
                    'read_at' => $message->read_at,
                ]);

                // Broadcast the message update
                broadcast(new MessageUpdated($message));
            } else {
                Log::warning('InstagramHandler: Message not found for read status', [
                    'external_id' => $messageId,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle read status', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $attachments = $messaging['message']['attachments'] ?? [];

        if (empty($attachments)) {
            return;
        }

        $attachment = $attachments[0];
        $mediaUrl = $attachment['payload']['url'] ?? null;

        if (!$mediaUrl) {
            Log::warning('InstagramHandler: No media URL found in attachment', [
                'message_id' => $message->id,
                'attachment' => $attachment,
            ]);
            return;
        }

        try {
            $connection = $message->conversation->connection;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('InstagramHandler: Missing access token', [
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Download media from Instagram
            $response = Http::withToken($accessToken)->get($mediaUrl);

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to download media', [
                    'message_id' => $message->id,
                    'url' => $mediaUrl,
                    'status' => $response->status(),
                ]);
                return;
            }

            // Determine extension from mime type or URL
            $mimeType = $response->header('Content-Type');
            $extension = $this->getExtensionFromMimeType($mimeType) ?? 'bin';

            // Save media file
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $response->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);

            Log::info('InstagramHandler: Media downloaded successfully', [
                'message_id' => $message->id,
                'media_path' => $mediaPath,
            ]);

        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function updateContactInfo(Contact $contact, Connection $connection, string $instagramUserId)
    {
        try {
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$accessToken) {
                return;
            }

            // Fetch user info from Instagram API
            $response = Http::get("https://graph.instagram.com/v25.0/{$instagramUserId}", [
                'fields' => 'name,username,profile_pic',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $userInfo = $response->json();

                $contact->update([
                    'name' => $userInfo['name'] ?? $userInfo['username'] ?? $instagramUserId,
                    'username' => $userInfo['username'] ?? null,
                    'profile_pic' => $userInfo['profile_pic'] ?? null,
                ]);

                Log::info('InstagramHandler: Contact info updated', [
                    'contact_id' => $contact->id,
                    'name' => $userInfo['name'] ?? null,
                    'username' => $userInfo['username'] ?? null,
                    'response' => $userInfo,
                ]);
            } else {
                Log::warning('InstagramHandler: Failed to fetch user info from Instagram', [
                    'contact_id' => $contact->id,
                    'instagram_user_id' => $instagramUserId,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Error updating contact info', [
                'contact_id' => $contact->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        // Clean mime type (remove charset or other parameters)
        $cleanMimeType = explode(';', $mimeType)[0];

        return match($cleanMimeType) {
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            default => 'bin',
        };
    }

    private function handleInstagramShare(Message $message, array $attachment, Connection $connection)
    {
        $type = $attachment['type'] ?? null;
        $payload = $attachment['payload'] ?? [];
        $title = $payload['title'] ?? null;

        // Get media ID from different possible fields
        $mediaId = $payload['reel_video_id']
                ?? $payload['ig_post_media_id']
                ?? null;

        if (!$mediaId) {
            Log::warning('InstagramHandler: No media ID found for Instagram share', [
                'message_id' => $message->id,
                'type' => $type,
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        try {
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('InstagramHandler: Missing access token for fetching permalink', [
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Fetch permalink from Instagram Graph API
            $response = Http::get("https://graph.instagram.com/v21.0/{$mediaId}", [
                'fields' => 'permalink',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $permalink = $data['permalink'] ?? null;

                if ($permalink) {
                    // Update message body with Instagram permalink
                    $newBody = $title ? "$title\n$permalink" : $permalink;

                    $message->update([
                        'body' => $newBody,
                    ]);

                    Log::info('InstagramHandler: Instagram permalink fetched successfully', [
                        'message_id' => $message->id,
                        'permalink' => $permalink,
                    ]);
                }
            } else {
                Log::warning('InstagramHandler: Failed to fetch Instagram permalink', [
                    'message_id' => $message->id,
                    'media_id' => $mediaId,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Error fetching Instagram permalink', [
                'message_id' => $message->id,
                'media_id' => $mediaId,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
