<?php

namespace App\Services\Push;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\FanOutPushNotification;
use App\Jobs\SendPushNotification;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\Contact\ContactIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who gets a push on their phone, and what it says.
 *
 * Mirrors the dashboard's toast rules (ConnectionContext.tsx) on purpose — the
 * phone and the open tab should agree about what is worth an interruption:
 *
 *  - message_received: owners for every thread; agents for Pending threads
 *    (anyone may pick those up) and for threads assigned to them.
 *  - conversation_handoff: everyone who can see the connection.
 *  - conversation_transferred: the agent receiving it.
 *  - conversation_taken_over: the agent losing it.
 *
 * ⚠️ Unlike the dashboard, the rules are enforced HERE. The socket is shared by
 * every agent on a connection, so the browser filters for itself; a push is
 * addressed to one person, so muted threads, Settings → Notifications and
 * connection access are all checked server-side before anything leaves.
 *
 * ⚠️ The message content is never sent (product decision): the title names the
 * contact, the body says what happened.
 */
class PushNotifier
{
    public const MESSAGE_RECEIVED = 'message_received';

    public const HANDOFF = 'conversation_handoff';

    public const TRANSFERRED = 'conversation_transferred';

    public const TAKEN_OVER = 'conversation_taken_over';

    public function __construct(protected FcmClient $fcm) {}

    /**
     * Event side: cheap, runs inside the request that caused it. Only decides
     * whether the fan-out is worth queuing at all — most workspaces have no
     * phone registered, and they must not pay a job per incoming message.
     */
    public static function queue(string $type, Conversation $conversation, array $context = []): void
    {
        try {
            if (! config('push.enabled') || ! FirebaseConfig::isConfigured()) {
                return;
            }

            // Conversations carry no tenant column; the connection does.
            $tenantId = $conversation->connection?->tenant_id;
            if (! $tenantId || ! DeviceToken::query()->where('tenant_id', $tenantId)->exists()) {
                return;
            }

            FanOutPushNotification::dispatch($type, (int) $conversation->id, $context)
                ->onQueue(config('push.queue'))
                ->afterCommit();
        } catch (Throwable $e) {
            // A notification is never worth failing the thing it announces.
            Log::warning('Push notification could not be queued', [
                'type' => $type,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function messageReceived(Message $message): void
    {
        if ($message->sender_type !== SenderType::Incoming || $message->message_type === MessageType::Info) {
            return;
        }

        $conversation = $message->conversation;
        if ($conversation) {
            self::queue(self::MESSAGE_RECEIVED, $conversation);
        }
    }

    /**
     * Job side: pick the recipients, word the notification in each phone's
     * language, queue one send per phone.
     *
     * @param  array{agent_id?: int, from_agent_id?: int, to_agent_id?: int}  $context
     */
    public function fanOut(string $type, int $conversationId, array $context = []): int
    {
        $conversation = Conversation::query()->with(['contact', 'connection'])->find($conversationId);
        if (! $conversation) {
            return 0;
        }

        // A muted thread stays quiet for its messages and its handoff — the
        // same rule as the dashboard. A transfer or take-over is about the
        // agent, not the thread, and is announced regardless.
        if ($conversation->isMuted() && in_array($type, [self::MESSAGE_RECEIVED, self::HANDOFF], true)) {
            return 0;
        }

        $tenantId = $conversation->connection?->tenant_id;
        if (! $tenantId) {
            return 0;
        }

        $devices = DeviceToken::query()
            ->where('tenant_id', $tenantId)
            ->with('user.tenant.market')
            ->get()
            ->groupBy('user_id');

        $queued = 0;

        foreach ($devices as $userDevices) {
            $user = $userDevices->first()->user;
            if (! $user || (int) $user->tenant_id !== (int) $tenantId
                || ! $this->shouldNotify($type, $user, $conversation, $context)) {
                continue;
            }

            foreach ($userDevices as $device) {
                $message = $this->build($type, $conversation, $device, $context);
                SendPushNotification::dispatch($device->id, $message)->onQueue(config('push.queue'));
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * A test notification to one person's own phones, sent synchronously so the
     * answer is on the screen that asked for it.
     *
     * @param  Collection<int, DeviceToken>  $devices
     * @return array<int, array{device_id: string, ok: bool, error: string|null}>
     */
    public function sendTest(Collection $devices): array
    {
        return $devices->map(function (DeviceToken $device) {
            $locale = $this->localeFor($device);
            $result = $this->deliver($device, [
                'notification' => [
                    'title' => $this->text('test_title', $locale),
                    'body' => $this->text('test_body', $locale),
                ],
                'data' => ['type' => 'test', 'url' => '/conversations'],
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'messages']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]);

            return [
                'device_id' => $device->device_id,
                'ok' => $result->sent(),
                'error' => $result->sent() ? null : $result->error,
            ];
        })->values()->all();
    }

    /**
     * One send, with the bookkeeping that keeps the table honest: a dead token
     * is deleted, a failure is recorded on the row.
     */
    public function deliver(DeviceToken $device, array $message): PushResult
    {
        $result = $this->fcm->send($device->token, $message);

        DeviceToken::withoutTimestamps(function () use ($device, $result) {
            if ($result->outcome === PushResult::INVALID_TOKEN) {
                $device->delete();

                return;
            }

            $device->forceFill($result->sent()
                ? ['last_sent_at' => now(), 'last_error' => null]
                : ['last_failed_at' => now(), 'last_error' => mb_substr((string) $result->error, 0, 191)]
            )->saveQuietly();
        });

        return $result;
    }

    private function shouldNotify(string $type, User $user, Conversation $conversation, array $context): bool
    {
        if (! $user->canAccessConnection($conversation->connection)) {
            return false;
        }

        // An exclusive thread is none of the other agents' business — not
        // even as "a new message from Ana". Taken-over notices stay: they tell
        // the person who lost the thread, who could read it until then.
        if ($type !== self::TAKEN_OVER && ! $conversation->isReadableBy($user)) {
            return false;
        }

        return match ($type) {
            self::MESSAGE_RECEIVED => $this->wantsMessages($user, $conversation)
                && ($user->canAccessAllConnections()
                    || $conversation->status === Status::Pending
                    || (int) $conversation->user_id === (int) $user->id),
            self::HANDOFF => true,
            self::TRANSFERRED => (int) ($context['to_agent_id'] ?? 0) === (int) $user->id,
            self::TAKEN_OVER => (int) ($context['from_agent_id'] ?? 0) === (int) $user->id,
            default => false,
        };
    }

    /** Settings → Notifications, the same two switches the dashboard reads. */
    private function wantsMessages(User $user, Conversation $conversation): bool
    {
        $settings = $user->notificationSettings();

        return $settings['incoming_messages']
            && ! in_array((int) $conversation->connection_id, $settings['muted_connection_ids'], true);
    }

    /** @return array<string, mixed> */
    private function build(string $type, Conversation $conversation, DeviceToken $device, array $context): array
    {
        $locale = $this->localeFor($device);
        $tag = 'conv-'.$conversation->id;
        $channel = match ($type) {
            self::HANDOFF => 'handoff',
            self::TAKEN_OVER => 'activity',
            default => 'messages',
        };

        return [
            'notification' => [
                'title' => $this->contactName($conversation, $locale),
                'body' => $this->text($type, $locale, ['agent' => (string) ($context['agent_name'] ?? '')]),
            ],
            // FCM data values must all be strings.
            'data' => [
                'type' => $type,
                'conversation_id' => (string) $conversation->id,
                'url' => '/conversations?conversation='.$conversation->id,
            ],
            'android' => [
                'priority' => 'high',
                'collapse_key' => $tag,
                'notification' => ['channel_id' => $channel, 'tag' => $tag],
            ],
            'apns' => [
                'headers' => ['apns-collapse-id' => $tag],
                'payload' => ['aps' => array_filter([
                    'sound' => $type === self::TAKEN_OVER ? null : 'default',
                    'thread-id' => $tag,
                ])],
            ],
        ];
    }

    private function contactName(Conversation $conversation, string $locale): string
    {
        $contact = $conversation->contact;
        $identity = ContactIdentity::for($contact);
        $name = ContactIdentity::name($contact)
            ?? ($contact?->is_group ? trim((string) $contact->name) : null)
            ?: ($identity['phone'] ?? $identity['email'] ?? null);

        return $name ?: $this->text('a_contact', $locale);
    }

    private function localeFor(DeviceToken $device): string
    {
        $locales = array_keys(config('markets.locales', []));
        $candidate = $device->locale ?: $device->user?->localeCode();

        return in_array($candidate, $locales, true) ? $candidate : (string) config('markets.default_locale', 'pt_BR');
    }

    /** @param  array<string, string>  $params */
    private function text(string $key, string $locale, array $params = []): string
    {
        $line = (string) __("push.{$key}", [], $locale);
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{{'.$name.'}}'] = $value;
        }

        return trim(strtr($line, $replace));
    }
}
