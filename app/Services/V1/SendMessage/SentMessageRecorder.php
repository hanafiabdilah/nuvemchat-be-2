<?php

namespace App\Services\V1\SendMessage;

use App\Enums\Broadcast\AddressType;
use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Conversation\OutboundConversationResolver;
use App\Services\Message\ApiwaySendQueue;
use App\Support\PhoneVariants;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Puts a WhatsApp message sent through POST /v1/send-message in the inbox.
 *
 * Nothing else will. The Cloud API never echoes what it sent, and neither does
 * API Way's core: whatsmeow reports messages typed on the phone, not the ones
 * its own client sent. So a send to a number with no open thread used to reach
 * the customer and leave nothing in Pingly — no thread, no bubble — and when the
 * customer answered, a brand-new conversation opened with no trace of what they
 * were answering.
 *
 * The other channels already have their record: Telegram and TikTok write it in
 * their own handlers, and Instagram and Messenger get it from the is_echo
 * webhook, which also opens the thread. Discord is the one gap left — its echo
 * only attaches to a thread that already exists.
 *
 * The thread rules are the ones every business-initiated send follows
 * (OutboundConversationResolver): an open thread is continued without being
 * moved, otherwise a new one waits in the Pending queue with nobody assigned.
 * The API is not an agent, and an Active thread nobody owns is the state
 * "Assumir chat" exists to repair. The customer's answer still gets the
 * connection's flow, as it did when that answer opened a thread of its own —
 * FlowRunner::opensThread does not count these messages as somebody having
 * already spoken to them.
 *
 * Never throws: the message is already on the customer's phone, and an error
 * here would invite the caller to send it again.
 */
final class SentMessageRecorder
{
    /** `messages.meta` key marking a message sent through the public API. */
    public const META_KEY = 'sent_via';

    public const VIA_API = 'api';

    /**
     * @param  string  $phone  The recipient as WhatsApp knows them. The Cloud API
     *                         reports it (`contacts[0].wa_id`) and that spelling is
     *                         the one its webhook will use when they answer; API Way
     *                         does not, so it is the number the caller typed.
     * @param  array<string, mixed>  $response  What the channel answered, kept as the
     *                                          dashboard's own sends keep it.
     */
    public static function whatsapp(
        Connection $connection,
        string $phone,
        OutgoingMessage $outgoing,
        ?string $messageId,
        array $response,
    ): ?Message {
        $phone = AddressType::Phone->normalize($phone);
        $messageId = $messageId !== '' ? $messageId : null;

        if (! AddressType::Phone->isValid($phone)) {
            Log::warning('V1 send message: sent, but the recipient is not a phone number we can file it under', [
                'connection_id' => $connection->id,
                'message_id' => $messageId,
            ]);

            return null;
        }

        try {
            // Two sends to a new number in the same moment (a text and its
            // image, fired in parallel) would otherwise open two threads.
            $message = Cache::lock("v1-send-message:{$connection->id}:{$phone}", 10)
                ->block(5, fn () => DB::transaction(
                    fn () => self::store($connection, $phone, $outgoing, $messageId, $response)
                ));
        } catch (\Throwable $th) {
            Log::error('V1 send message: sent, but could not be recorded in the inbox', [
                'connection_id' => $connection->id,
                'message_id' => $messageId,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            return null;
        }

        if ($message) {
            // Before the broadcast, so the bubble arrives already saying it
            // is waiting in API Way's queue rather than looking sent.
            if ($connection->channel === Channel::WhatsappApiway) {
                ApiwaySendQueue::track($message, $connection);
            }

            self::quietly(fn () => broadcast(new MessageReceived($message)));
            self::quietly(fn () => broadcast(new ConversationUpdated($message->conversation->load('contact'))));
        }

        return $message;
    }

    private static function store(
        Connection $connection,
        string $phone,
        OutgoingMessage $outgoing,
        ?string $messageId,
        array $response,
    ): ?Message {
        if ($messageId !== null && self::alreadyRecorded($connection, $messageId)) {
            return null;
        }

        $contact = self::contactFor($connection, $phone);

        // Never null on WhatsApp: the thread's address is the phone number itself.
        $conversation = app(OutboundConversationResolver::class)->resolve(
            $connection,
            $contact,
            // A thread already open is somebody's work in progress — an agent's,
            // or the AI's mid-turn — and the API adds to it without moving it.
            activateOnReuse: false,
            createAs: Status::Pending,
        )->conversation;

        $meta = $response + [self::META_KEY => self::VIA_API];

        // Where the document bubble and "save file" read the name from (see
        // MessageResource::getFilenameMeta); the URL's last segment is often
        // `download?id=42`.
        if ($outgoing->type === 'document') {
            $meta['filename'] = $outgoing->filename();
        }

        return $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Outgoing,
            'message_type' => $outgoing->messageType(),
            'body' => $outgoing->isText() ? $outgoing->text : $outgoing->caption(),
            // The caller's URL, as Telegram's API sends and the dashboard's
            // by-URL sends store it: nothing was downloaded to keep a copy of.
            'attachment' => $outgoing->isText() ? null : $outgoing->url(),
            'sent_at' => now(),
            'meta' => $meta,
        ]);
    }

    /** A retry the channel deduplicated, or a row an echo got to first. */
    private static function alreadyRecorded(Connection $connection, string $messageId): bool
    {
        return Message::where('external_id', $messageId)
            ->whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->exists();
    }

    /**
     * Someone the inbox already knows, under either spelling of a Brazilian
     * mobile number (see PhoneVariants), keeps their row and
     * the name it shows for them.
     */
    private static function contactFor(Connection $connection, string $phone): Contact
    {
        $existing = Contact::where('tenant_id', $connection->tenant_id)
            ->where('is_group', false)
            ->whereIn('external_id', PhoneVariants::of($phone))
            ->orderByRaw('external_id = ? desc', [$phone])
            ->first();

        return $existing ?? Contact::createFromExternalData(
            $connection,
            $phone,
            // The number until they answer: the webhook replaces a name that is
            // only the id with the one their WhatsApp profile carries.
            $phone,
            // API Way stores the number as the username too (see its webhook handler).
            $connection->channel === Channel::WhatsappApiway ? $phone : null,
        );
    }

    /**
     * A dashboard that does not light up is a small problem; an API call that
     * fails after the customer's phone already buzzed is a large one.
     */
    private static function quietly(callable $announce): void
    {
        try {
            $announce();
        } catch (\Throwable $th) {
            Log::warning('V1 send message: realtime announcement failed', ['error' => $th->getMessage()]);
        }
    }
}
