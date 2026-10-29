<?php

namespace App\Services\Instagram\CommentDm;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Exceptions\InstagramApiException;
use App\Jobs\SyncContactPhoto;
use App\Jobs\SyncContactProfile;
use App\Models\CommentDmAutomation;
use App\Models\CommentDmRun;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Instagram\InstagramGraphClientFactory;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Comentário → Direct": a comment on an Instagram post that matches one of the
 * account's rules is answered with a private message — and, when the rule has
 * some, a short public reply under it ("te chamei no direct!").
 *
 * Why a DM and not a clever public answer: prices, links and stock are the
 * questions comments ask most, and the place to answer them is a conversation
 * the team can see and follow up — the inbox. The private message is filed
 * there as soon as it is sent, and the commenter's answer runs the
 * connection's flow like any first message would (FlowRunner::opensThread
 * does not count this message as somebody already answering).
 *
 * Instagram's rules shape the rest: one private reply per comment, within
 * seven days of it, and nothing more until the person answers. So the DM goes
 * first, and the public reply only once the DM is out — a "check your
 * direct!" under a comment whose DM failed would be a public lie.
 */
class CommentDmAutomations
{
    /** `messages.meta.sent_via` on the private message, for FlowRunner. */
    public const VIA = 'comment_dm';

    public const MAX_PER_ACCOUNT = 20;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    /**
     * Whether a comment event is worth a job at all: a top-level comment by
     * someone else, on an account with at least one rule switched on. Checked
     * in the webhook, so a viral post on an account without rules costs one
     * indexed query per comment and nothing else.
     *
     * Replies under a comment are left alone: the account's own public reply
     * comes back as one of them, and a person answering a friend is not
     * asking the account anything.
     */
    public static function shouldConsider(Connection $connection, array $comment): bool
    {
        if (empty($comment['id']) || ! empty($comment['parent_id']) || self::isFromAccount($connection, $comment)) {
            return false;
        }

        return CommentDmAutomation::where('connection_id', $connection->id)->where('enabled', true)->exists();
    }

    /** The first switched-on rule the comment matches: ones for specific posts before ones for every post. */
    public static function match(Connection $connection, array $comment): ?CommentDmAutomation
    {
        $mediaId = (string) ($comment['media']['id'] ?? '');
        $text = (string) ($comment['text'] ?? '');

        $rules = CommentDmAutomation::where('connection_id', $connection->id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (CommentDmAutomation $rule) => $rule->media_ids === null ? 1 : 0)
            ->values();

        foreach ($rules as $rule) {
            if ($rule->media_ids !== null && ! in_array($mediaId, array_map('strval', $rule->media_ids), true)) {
                continue;
            }

            if ($rule->match_mode === CommentDmAutomation::MATCH_ANY || self::containsKeyword($text, $rule->keywords ?? [])) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Whole words, case and accents aside: "eu quero" matches "EU QUERO!!" and
     * "Eu quéro o azul", but "link" does not match "linkedin". A keyword with
     * no letters or digits (an emoji) is matched as it is written.
     *
     * @param  list<string>  $keywords
     */
    public static function containsKeyword(string $text, array $keywords): bool
    {
        $haystack = self::normalize($text);

        foreach ($keywords as $keyword) {
            $needle = self::normalize((string) $keyword);

            if ($needle === '') {
                continue;
            }

            if (! preg_match('/[\p{L}\p{N}]/u', $needle)) {
                if (mb_strpos($text, trim((string) $keyword)) !== false) {
                    return true;
                }

                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Act on one comment. Returns the run row, or null when no rule matched or
     * the comment was already handled (Meta delivers a webhook more than once).
     */
    public function run(Connection $connection, array $comment): ?CommentDmRun
    {
        $rule = self::match($connection, $comment);

        if (! $rule) {
            return null;
        }

        $commenterId = isset($comment['from']['id']) ? (string) $comment['from']['id'] : null;
        $username = isset($comment['from']['username']) ? (string) $comment['from']['username'] : null;
        $mediaId = isset($comment['media']['id']) ? (string) $comment['media']['id'] : null;

        try {
            $run = CommentDmRun::create([
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'automation_id' => $rule->id,
                'comment_id' => (string) $comment['id'],
                'media_id' => $mediaId,
                'commenter_id' => $commenterId,
                'commenter_username' => $username,
                'comment_text' => mb_substr((string) ($comment['text'] ?? ''), 0, 2200),
                'status' => CommentDmRun::STATUS_PROCESSING,
            ]);
        } catch (QueryException) {
            // The unique (connection, comment): this delivery is a repeat.
            return null;
        }

        // One message per person per post: someone who comments "eu quero"
        // three times wants the link once.
        if ($commenterId !== null && $mediaId !== null && CommentDmRun::where('connection_id', $connection->id)
            ->where('media_id', $mediaId)
            ->where('commenter_id', $commenterId)
            ->where('status', CommentDmRun::STATUS_SENT)
            ->where('id', '!=', $run->id)
            ->exists()) {
            $run->update(['status' => CommentDmRun::STATUS_SKIPPED, 'reason' => 'already_messaged']);

            return $run;
        }

        $client = $this->clients->for($connection);
        $dm = self::render($rule->dm_message, $username);

        try {
            $sent = $client->sendPrivateReply((string) $comment['id'], $dm);
        } catch (\Throwable $th) {
            $run->update([
                'status' => CommentDmRun::STATUS_FAILED,
                'reason' => 'dm_failed',
                'error' => self::failure($th, $connection, 'instagram.comment_dm'),
            ]);

            return $run;
        }

        $conversation = null;

        try {
            $conversation = $this->fileInInbox($connection, $rule, $comment, $dm, $sent);
        } catch (\Throwable $th) {
            // The message is with the person already; failing to file it here
            // is a gap in the inbox, not a reason to report the DM as failed.
            Log::error('CommentDm: the private reply went out but could not be filed in the inbox', [
                'connection_id' => $connection->id,
                'comment_id' => $comment['id'],
                'error' => $th->getMessage(),
            ]);
        }

        $updates = [
            'status' => CommentDmRun::STATUS_SENT,
            'dm_message_id' => $sent['message_id'] ?? null,
            'conversation_id' => $conversation?->id,
        ];

        $replies = array_values(array_filter(array_map('trim', $rule->public_replies ?? [])));

        if ($replies !== []) {
            try {
                $reply = $client->replyToComment((string) $comment['id'], self::render(Arr::random($replies), $username));
                $updates['public_reply_id'] = isset($reply['id']) ? (string) $reply['id'] : null;
            } catch (\Throwable $th) {
                $updates['reason'] = 'public_reply_failed';
                $updates['error'] = self::failure($th, $connection, 'instagram.comment_dm_reply');
            }
        }

        $run->update($updates);

        return $run;
    }

    /**
     * The private message, as an outgoing message in the commenter's thread —
     * opening one when they have none. Instagram also echoes the message back
     * through the webhook; whichever arrives second finds the other's row by
     * the message id and only adds what it knows.
     */
    private function fileInInbox(Connection $connection, CommentDmAutomation $rule, array $comment, string $text, array $sent): ?Conversation
    {
        $recipient = isset($sent['recipient_id']) ? (string) $sent['recipient_id'] : null;
        $messageId = isset($sent['message_id']) ? (string) $sent['message_id'] : null;

        if (! $recipient || ! $messageId) {
            return null;
        }

        $username = $comment['from']['username'] ?? null;
        $meta = [
            'sent_via' => self::VIA,
            'comment_dm' => [
                'automation_id' => $rule->id,
                'comment_id' => (string) $comment['id'],
                'media_id' => $comment['media']['id'] ?? null,
                'comment_text' => mb_substr((string) ($comment['text'] ?? ''), 0, 500),
            ],
        ];

        [$conversation, $message, $contact] = Cache::lock("instagram-thread:{$connection->id}:{$recipient}", 10)
            ->block(5, fn () => DB::transaction(function () use ($connection, $recipient, $messageId, $username, $text, $meta) {
                $contact = Contact::createFromExternalData($connection, $recipient, $username ?: $recipient, $username);

                $conversation = Conversation::where('external_id', $recipient)
                    ->where('contact_id', $contact->id)
                    ->where('connection_id', $connection->id)
                    ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
                    ->first();

                $conversation ??= Conversation::create([
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id' => $recipient,
                    'status' => Status::Pending,
                ]);

                $message = $conversation->messages()->where('external_id', $messageId)->lockForUpdate()->first();

                if ($message) {
                    $message->update(['meta' => array_merge($message->meta ?? [], $meta)]);
                } else {
                    $message = $conversation->messages()->create([
                        'external_id' => $messageId,
                        'sender_type' => SenderType::Outgoing,
                        'message_type' => MessageType::Text,
                        'body' => $text,
                        'sent_at' => now(),
                        'delivery_at' => now(),
                        'meta' => $meta,
                    ]);
                }

                return [$conversation, $message, $contact];
            }));

        SyncContactProfile::dispatchIfUnresolved($contact, $connection);
        SyncContactPhoto::dispatchIfStale($contact, $connection);

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($conversation->load('contact')));

        return $conversation;
    }

    /** The rule's text with `{{username}}` filled in (empty when Instagram gave no name). */
    public static function render(string $template, ?string $username): string
    {
        return trim(str_replace('{{username}}', (string) $username, $template));
    }

    /** Whether the comment was written by the account itself — its own replies come back as comments too. */
    public static function isFromAccount(Connection $connection, array $comment): bool
    {
        $credentials = $connection->credentials ?? [];
        $fromId = isset($comment['from']['id']) ? (string) $comment['from']['id'] : null;
        $fromName = $comment['from']['username'] ?? null;

        if ($fromId !== null && in_array($fromId, array_filter([
            (string) ($credentials['user_id'] ?? ''),
            (string) ($credentials['instagram_account_id'] ?? ''),
        ]), true)) {
            return true;
        }

        $own = $credentials['username'] ?? null;

        return $own !== null && $fromName !== null && strcasecmp((string) $own, (string) $fromName) === 0;
    }

    /** Our sentence for a refusal; Meta's own words go to the log under a reference. */
    private static function failure(\Throwable $th, Connection $connection, string $operation): string
    {
        $code = $th instanceof InstagramApiException ? $th->metaCode() : null;

        return mb_substr(UpstreamError::message(
            UpstreamProvider::Meta,
            $th->getMessage(),
            upstreamCode: $code,
            status: $th instanceof InstagramApiException ? $th->httpStatus() : 502,
            context: ['operation' => $operation, 'connection_id' => $connection->id],
        ), 0, 1000);
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
