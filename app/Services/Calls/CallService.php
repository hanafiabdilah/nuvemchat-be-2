<?php

namespace App\Services\Calls;

use App\Enums\Call\CallStatus;
use App\Enums\Conversation\Status;
use App\Events\CallUpdated;
use App\Events\ConversationTakenOver;
use App\Events\ConversationUpdated;
use App\Events\IncomingCall;
use App\Events\MessageReceived;
use App\Exceptions\UpstreamServiceException;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Api\ConversationController;
use App\Jobs\SendPushNotification;
use App\Models\Call;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Observers\ConversationObserver;
use App\Services\Contact\ContactIdentity;
use App\Services\Conversation\CallLog;
use App\Services\Conversation\SystemMessage;
use App\Services\Flow\FlowExecutor;
use App\Services\Message\MessageService;
use App\Services\Messaging\MessagingWindow;
use App\Services\Push\FirebaseConfig;
use App\Services\Push\PushNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A WhatsApp call, from the moment Meta says the customer is ringing to the
 * moment somebody hangs up.
 *
 * The media never touches this server: the answering agent's browser is the
 * WebRTC endpoint, and what lives here is the signalling between it and Meta
 * plus the decisions only the server can make —
 *
 *  - **Who is rung.** Everybody who may answer and can be reached, at once:
 *    `calls.answer`, access to the connection (and to the thread, when it is
 *    exclusive), their own "receive calls" switch, Online, not already on a
 *    call, and either a dashboard that reported in recently or a phone with
 *    the app. There is no cap on calls per number (Meta allows a thousand at
 *    once); the people are the limit, one call each.
 *  - **Nobody can take it.** Refused at once rather than left to ring out —
 *    the customer would wait half a minute to be told nothing — and the
 *    connection's busy message is sent to the chat, when it has one. Same
 *    when everybody rung declines, and when it rings out.
 *  - **First answer wins.** Claimed under a row lock; every other phone stops
 *    ringing on the broadcast that says who took it.
 *  - **Answering is taking the conversation.** The agent on the phone with the
 *    customer owns the thread from then on, exactly as if they had pressed
 *    Accept — but nothing is sent to the customer: they are already talking.
 *  - **A call nobody hears the end of still ends.** The answering browser
 *    reports in every few seconds; `calls:sweep` hangs up on the ones that
 *    stopped, and closes those whose webhook never came.
 *
 * Every visible step is broadcast as `call-updated` on the connection channel,
 * which is how the other agents see that Ana is on the phone with a customer.
 */
class CallService
{
    public function __construct(private WhatsappCallingApi $api) {}

    /* ------------------------------------------------------------ incoming */

    /**
     * Meta's `connect`: the customer is calling. The thread and its note are
     * already written (CallLog) by the time this runs.
     */
    public function incoming(Connection $connection, Message $note, string $callId, ?string $offerSdp): ?Call
    {
        if (! config('calls.enabled')) {
            return null;
        }

        // A connect without an offer cannot be answered by anybody. It has
        // not been seen, but if it ever is, the note already says a call came
        // in, which is all that can honestly be said about it.
        if (! $offerSdp) {
            Log::warning('Call: connect without an SDP offer', [
                'connection_id' => $connection->id,
                'call_id' => $callId,
            ]);

            return null;
        }

        $conversation = $note->conversation()->with(['connection', 'contact'])->first();

        if (! $conversation) {
            return null;
        }

        try {
            $call = Call::create([
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'message_id' => $note->id,
                'channel' => $connection->channel,
                'external_id' => $callId,
                'direction' => 'incoming',
                'status' => CallStatus::Ringing,
                'offer_sdp' => $offerSdp,
                'ringing_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Unique (connection, call id): Meta delivered the same connect
            // twice. The first delivery is ringing the agents already.
            Log::info('Call: duplicate connect ignored', [
                'connection_id' => $connection->id,
                'call_id' => $callId,
            ]);

            return null;
        }

        $agents = $this->ringableAgents($call, $conversation);

        if ($agents->isEmpty()) {
            $this->refuse($call, Call::REASON_NO_AGENT);

            return $call;
        }

        $call->update(['rung_user_ids' => $agents->pluck('id')->map(fn ($id) => (int) $id)->values()->all()]);
        $call->setRelation('contact', $conversation->contact);
        $call->setRelation('connection', $connection);

        $this->announce($call);

        try {
            broadcast(new IncomingCall($call, $call->rung_user_ids));
        } catch (Throwable $e) {
            Log::warning('Call: ring broadcast failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }

        $this->pushRing($call, $agents);

        Log::info('Call: ringing', [
            'call_id' => $call->id,
            'connection_id' => $connection->id,
            'conversation_id' => $conversation->id,
            'rung' => $call->rung_user_ids,
        ]);

        return $call;
    }

    /**
     * Everybody who may answer this call and can be reached right now.
     *
     * @return Collection<int, User>
     */
    public function ringableAgents(Call $call, Conversation $conversation): Collection
    {
        $occupied = Call::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereIn('status', CallStatus::occupyingValues())
            ->whereNotNull('answered_by_user_id')
            ->pluck('answered_by_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $withPhone = DeviceToken::query()
            ->where('tenant_id', $call->tenant_id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return User::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereNull('offline_since')
            ->whereNotIn('id', $occupied ?: [0])
            ->get()
            ->filter(fn (User $user) => $this->mayAnswer($user, $conversation)
                && ($user->isOnline() || in_array((int) $user->id, $withPhone, true)))
            ->values();
    }

    /** The checks that do not depend on being reachable. */
    public function mayAnswer(User $user, Conversation $conversation): bool
    {
        $connection = $conversation->getRelationValue('connection') ?? $conversation->connection()->first();

        return (int) $user->tenant_id === (int) $connection?->tenant_id
            && $user->checkPermissionTo('calls.answer')
            && $user->canAccessConnection($connection)
            && $conversation->isReadableBy($user)
            && $user->notificationSettings()['calls'];
    }

    /* --------------------------------------------------------- answering */

    /**
     * Press Answer: the call is this agent's from now on, nobody else's phone
     * keeps ringing, and they get the offer their browser needs to reply to.
     *
     * @return array{call: Call, offer_sdp: string}
     */
    public function claim(Call $call, User $user): array
    {
        $conversation = $call->conversation()->with('connection')->first();

        if (! $conversation || ! $this->mayAnswer($user, $conversation)) {
            throw new UserFacingException('You cannot answer this call.', 403, 'call_not_allowed');
        }

        $claimed = DB::transaction(function () use ($call, $user) {
            $locked = Call::query()->lockForUpdate()->find($call->id);

            if (! $locked || $locked->status !== CallStatus::Ringing) {
                return $locked;
            }

            $busy = Call::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('answered_by_user_id', $user->id)
                ->whereIn('status', CallStatus::occupyingValues())
                ->where('id', '!=', $locked->id)
                ->exists();

            if ($busy) {
                throw new UserFacingException('You are already on another call.', 409, 'agent_on_another_call');
            }

            $locked->forceFill([
                'status' => CallStatus::Connecting,
                'answered_by_user_id' => $user->id,
                'claimed_at' => now(),
            ])->save();

            return $locked;
        });

        if (! $claimed || $claimed->status !== CallStatus::Connecting || (int) $claimed->answered_by_user_id !== (int) $user->id) {
            throw $this->noLongerRinging($claimed);
        }

        $claimed->load(['agent', 'contact']);
        $this->announce($claimed);
        $this->pushStop($claimed, 'call_answered_by', ['agent' => $user->name], exceptUserId: $user->id);

        return ['call' => $claimed, 'offer_sdp' => (string) $claimed->offer_sdp];
    }

    /**
     * The browser's answer is ready: hand it to Meta as `pre_accept`, which
     * lets the media path come up before the call is actually accepted.
     */
    public function answer(Call $call, User $user, string $sdp): Call
    {
        $call = $this->ownConnecting($call, $user);
        $call->forceFill(['answer_sdp' => $sdp])->save();

        try {
            $this->api->action($call->connection, $call->external_id, WhatsappCallingApi::PRE_ACCEPT, $sdp);
        } catch (UpstreamServiceException $e) {
            // Somebody else's browser may still manage what this one could
            // not, and Meta is still ringing: give the call back.
            $this->release($call, 'pre_accept_failed');

            throw $e;
        }

        return $call;
    }

    /**
     * Media is up (or the browser stopped waiting for it): accept. From here
     * the customer and the agent can hear each other.
     */
    public function accept(Call $call, User $user): Call
    {
        $call = $this->ownConnecting($call, $user);

        if (! $call->answer_sdp) {
            throw new UserFacingException('Answer the call before accepting it.', 409, 'call_not_answered');
        }

        try {
            $this->api->action($call->connection, $call->external_id, WhatsappCallingApi::ACCEPT, $call->answer_sdp);
        } catch (UpstreamServiceException $e) {
            $this->finish($call, CallStatus::Failed, Call::REASON_FAILED);
            $this->writeNote($call, CallLog::MISSED);

            throw $e;
        }

        $call->forceFill([
            'status' => CallStatus::Ongoing,
            'answered_at' => now(),
            'last_heartbeat_at' => now(),
            // Setup material; the answer is kept only as long as an accept
            // might have to repeat it.
            'offer_sdp' => null,
        ])->save();

        $call->load(['agent', 'contact']);

        $this->writeNote($call, CallLog::ONGOING, null, [
            'agent' => $user->name,
            'agent_id' => $user->id,
            'accepted_at' => now()->timestamp,
        ]);

        $this->takeConversation($call, $user);
        $this->announce($call);

        Log::info('Call: answered', [
            'call_id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'user_id' => $user->id,
        ]);

        return $call;
    }

    /**
     * Not now, for this agent only. The call keeps ringing for the others;
     * when nobody is left, it is refused like a call nobody could take.
     */
    public function decline(Call $call, User $user): Call
    {
        $refuse = DB::transaction(function () use ($call, $user) {
            $locked = Call::query()->lockForUpdate()->find($call->id);

            if (! $locked || $locked->status !== CallStatus::Ringing) {
                return false;
            }

            $declined = array_values(array_unique(array_merge(
                array_map('intval', $locked->declined_user_ids ?? []),
                [(int) $user->id],
            )));

            $locked->forceFill(['declined_user_ids' => $declined])->save();

            $remaining = array_diff(array_map('intval', $locked->rung_user_ids ?? []), $declined);

            return $remaining === [];
        });

        $call->refresh();

        if ($refuse) {
            $this->refuse($call, Call::REASON_DECLINED);
        }

        return $call->refresh();
    }

    /** The agent on the call hangs up (or gives up while it is connecting). */
    public function hangup(Call $call, User $user): Call
    {
        $call->refresh();

        if (! $call->status->occupiesAgent()) {
            return $call;
        }

        if ((int) $call->answered_by_user_id !== (int) $user->id && ! $user->canAccessAllConnections()) {
            throw new UserFacingException('Only the agent on this call can end it.', 403, 'call_not_yours');
        }

        // Still setting up: nothing was said yet, and another agent may be
        // able to take what this browser could not.
        if ($call->status === CallStatus::Connecting) {
            $this->release($call, 'agent_cancelled');

            return $call->refresh();
        }

        $this->terminateUpstream($call);
        $this->finish($call, CallStatus::Ended, Call::REASON_AGENT_HUNG_UP);
        $this->writeNote($call->refresh(), CallLog::ANSWERED, $call->duration_seconds);

        return $call;
    }

    /** "Still here" from the browser on the call. Returns the current state. */
    public function heartbeat(Call $call, User $user): Call
    {
        if ($call->status->occupiesAgent() && (int) $call->answered_by_user_id === (int) $user->id) {
            Call::query()->whereKey($call->id)->update(['last_heartbeat_at' => now()]);
        }

        return $call->refresh();
    }

    /* ------------------------------------------------------------ the end */

    /**
     * Meta's `terminate`. Fires for every way a call can end — customer hung
     * up, it rang out, we rejected or terminated it — so the state it lands
     * in depends on where the call was.
     */
    public function terminated(Call $call, ?int $duration, string $status): void
    {
        $call->refresh();

        if (! $call->status->isLive()) {
            // We ended it ourselves; Meta's duration is the one billed and the
            // one worth keeping.
            if ($duration !== null && $call->status === CallStatus::Ended) {
                $call->forceFill(['duration_seconds' => $duration])->save();
                $this->writeNote($call, CallLog::ANSWERED, $duration);
            }

            if ($call->status === CallStatus::Rejected) {
                // Keep "nobody could answer" rather than letting Meta's
                // COMPLETED turn it into a plain missed call.
                $this->writeNote($call, CallLog::BUSY);
            }

            return;
        }

        $answered = $call->status === CallStatus::Ongoing;

        if ($answered) {
            $this->finish($call, CallStatus::Ended, Call::REASON_CUSTOMER_HUNG_UP, $duration);
            $this->writeNote($call->refresh(), CallLog::ANSWERED, $call->duration_seconds);

            return;
        }

        $wasRinging = $call->status === CallStatus::Ringing;

        $this->finish(
            $call,
            CallStatus::Missed,
            $wasRinging ? Call::REASON_UNANSWERED : Call::REASON_CUSTOMER_HUNG_UP,
        );
        $this->writeNote($call, strtoupper($status) === 'REJECTED' ? CallLog::DECLINED : CallLog::MISSED);

        // It rang out with nobody picking up: as much a call nobody could take
        // as one refused at once, and the customer deserves the same line.
        if ($wasRinging) {
            $this->sendBusyMessage($call);
        }
    }

    /**
     * Close what the webhooks and the browsers left open. Run every minute by
     * `calls:sweep`; each rule only fires well past the point where the
     * normal path would have closed the call already.
     *
     * @return array{expired: int, failed: int, lost: int}
     */
    public function sweep(): array
    {
        $counts = ['expired' => 0, 'failed' => 0, 'lost' => 0];

        Call::query()
            ->where('status', CallStatus::Ringing->value)
            ->where('ringing_at', '<', now()->subSeconds((int) config('calls.ringing_timeout_seconds', 90)))
            ->each(function (Call $call) use (&$counts) {
                $this->finish($call, CallStatus::Missed, Call::REASON_EXPIRED);
                $this->writeNote($call, CallLog::MISSED);
                $counts['expired']++;
            });

        Call::query()
            ->where('status', CallStatus::Connecting->value)
            ->where('claimed_at', '<', now()->subSeconds((int) config('calls.connecting_timeout_seconds', 60)))
            ->each(function (Call $call) use (&$counts) {
                $this->terminateUpstream($call);
                $this->finish($call, CallStatus::Failed, Call::REASON_FAILED);
                $this->writeNote($call, CallLog::MISSED);
                $counts['failed']++;
            });

        $lostBefore = now()->subSeconds((int) config('calls.lost_after_seconds', 45));
        $tooLong = now()->subMinutes((int) config('calls.max_duration_minutes', 240));

        Call::query()
            ->where('status', CallStatus::Ongoing->value)
            ->where(fn ($q) => $q->where('last_heartbeat_at', '<', $lostBefore)
                ->orWhereNull('last_heartbeat_at')
                ->orWhere('answered_at', '<', $tooLong))
            ->each(function (Call $call) use (&$counts) {
                $this->terminateUpstream($call);
                $this->finish($call, CallStatus::Ended, Call::REASON_LOST);
                $this->writeNote($call->refresh(), CallLog::ANSWERED, $call->duration_seconds);
                $counts['lost']++;

                Log::warning('Call: ended because the answering browser stopped reporting in', [
                    'call_id' => $call->id,
                    'user_id' => $call->answered_by_user_id,
                ]);
            });

        return $counts;
    }

    /* ----------------------------------------------------------- internals */

    /** Turn the call away at once, and tell the customer why when we can. */
    private function refuse(Call $call, string $reason): void
    {
        try {
            $this->api->action($call->connection, $call->external_id, WhatsappCallingApi::REJECT);
        } catch (Throwable $e) {
            // Meta times it out on its own; the customer just waits longer.
            Log::warning('Call: reject failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }

        $this->finish($call, CallStatus::Rejected, $reason);
        $this->writeNote($call, CallLog::BUSY);
        $this->sendBusyMessage($call);

        Log::info('Call: refused', ['call_id' => $call->id, 'reason' => $reason]);
    }

    /** Back to ringing, for everybody still rung, after a claim that failed. */
    private function release(Call $call, string $why): void
    {
        $call->forceFill([
            'status' => CallStatus::Ringing,
            'answered_by_user_id' => null,
            'claimed_at' => null,
            'answer_sdp' => null,
        ])->save();

        $call->setRelation('agent', null);
        $this->announce($call);

        Log::info('Call: claim released', ['call_id' => $call->id, 'why' => $why]);
    }

    private function finish(Call $call, CallStatus $status, string $reason, ?int $duration = null): void
    {
        $wasRinging = $call->status === CallStatus::Ringing;
        $elapsed = $call->answered_at ? (int) $call->answered_at->diffInSeconds(now(), absolute: true) : null;

        $call->forceFill([
            'status' => $status,
            'end_reason' => $call->end_reason ?? $reason,
            'ended_at' => $call->ended_at ?? now(),
            'duration_seconds' => $duration ?? $call->duration_seconds ?? $elapsed,
            'offer_sdp' => null,
            'answer_sdp' => null,
        ])->save();

        $call->load(['agent', 'contact']);
        $this->announce($call);

        if ($wasRinging) {
            $this->pushStop($call, 'call_missed');
        }
    }

    /** @return Call The row, locked to this agent's claim. */
    private function ownConnecting(Call $call, User $user): Call
    {
        $call->refresh()->loadMissing('connection');

        if ($call->status !== CallStatus::Connecting || (int) $call->answered_by_user_id !== (int) $user->id) {
            throw $this->noLongerRinging($call);
        }

        return $call;
    }

    private function noLongerRinging(?Call $call): UserFacingException
    {
        $by = $call?->answered_by_user_id ? $call->agent()->value('name') : null;

        return $by
            ? new UserFacingException("{$by} already answered this call.", 409, 'call_already_answered')
            : new UserFacingException('This call is no longer ringing.', 409, 'call_not_ringing');
    }

    private function terminateUpstream(Call $call): void
    {
        try {
            $this->api->action($call->connection, $call->external_id, WhatsappCallingApi::TERMINATE);
        } catch (Throwable $e) {
            // Usually it is over already on Meta's side.
            Log::info('Call: terminate not accepted', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The person on the phone with the customer owns the thread — exactly as
     * if they had pressed Accept (Pending, or the AI's) or Take over (another
     * agent's). Nothing is sent to the customer: they are mid-conversation.
     */
    private function takeConversation(Call $call, User $user): void
    {
        $conversation = Conversation::query()->with(['connection', 'agent'])->find($call->conversation_id);

        if (! $conversation || $conversation->status === Status::Resolved) {
            return;
        }

        if ($conversation->status === Status::Active && (int) $conversation->user_id === (int) $user->id) {
            return;
        }

        $previous = $conversation->status === Status::Active ? $conversation->agent : null;
        $wasAi = $conversation->status === Status::AiHandling;
        $wasQueued = in_array($conversation->status, [Status::Pending, Status::AiHandling], true);

        DB::transaction(function () use ($conversation, $user) {
            $locked = Conversation::query()->lockForUpdate()->find($conversation->id);

            if (! $locked || $locked->status === Status::Resolved) {
                return;
            }

            $locked->user_id = $user->id;
            $locked->status = Status::Active;
            $locked->needs_human = false;

            ConversationObserver::withoutStatusNote(fn () => $locked->save());
        });

        $conversation->refresh()->load(['connection', 'agent', 'contact']);

        if ($wasQueued) {
            if ($wasAi) {
                (new FlowExecutor)->stopFlow($conversation);
            }

            SystemMessage::info(
                $conversation,
                $user->name.' took this conversation.',
                ConversationController::INFO_ASSIGNED,
                ['to' => $user->name],
            );
        } elseif ($previous) {
            SystemMessage::info(
                $conversation,
                "{$user->name} took over this conversation from {$previous->name}.",
                ConversationController::INFO_TAKEN_OVER,
                ['from' => $previous->name, 'to' => $user->name],
            );
        }

        broadcast(new ConversationUpdated($conversation));

        if ($previous && (int) $previous->id !== (int) $user->id) {
            broadcast(new ConversationTakenOver($conversation, $previous, $user));
        }

        Log::info('Conversation taken by answering a call', [
            'conversation_id' => $conversation->id,
            'call_id' => $call->id,
            'from_user_id' => $previous?->id,
            'to_user_id' => $user->id,
        ]);
    }

    /**
     * The workspace's line for a call nobody could take. Once per call, only
     * when the connection has one, and only inside the reply window — outside
     * it Cloud API refuses free text, and a template is not ours to pick.
     */
    private function sendBusyMessage(Call $call): void
    {
        try {
            if (data_get($call->meta, 'busy_message_sent')) {
                return;
            }

            $text = trim((string) $call->connection?->call_busy_message);
            $conversation = $call->conversation()->with(['connection', 'contact'])->first();

            if ($text === '' || ! $conversation || $conversation->status === Status::Resolved) {
                return;
            }

            if (! MessagingWindow::isOpen($conversation)) {
                Log::info('Call: busy message not sent, reply window closed', ['call_id' => $call->id]);

                return;
            }

            $call->forceFill(['meta' => array_merge($call->meta ?? [], ['busy_message_sent' => true])])->save();

            $message = (new MessageService)->sendMessage($conversation, ['message' => $text]);

            if ($message) {
                broadcast(new MessageReceived($message));
                broadcast(new ConversationUpdated($message->conversation));
            }
        } catch (Throwable $e) {
            // The refusal already happened; failing to explain it is not worth
            // failing the webhook over.
            Log::warning('Call: busy message failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $detail */
    private function writeNote(Call $call, string $code, ?int $seconds = null, array $detail = []): void
    {
        $note = $call->message_id ? Message::query()->find($call->message_id) : null;

        if (! $note) {
            return;
        }

        try {
            CallLog::progress($note, $code, $seconds, $detail);
        } catch (Throwable $e) {
            Log::warning('Call: note update failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    private function announce(Call $call): void
    {
        try {
            broadcast(new CallUpdated($call));
        } catch (Throwable $e) {
            Log::warning('Call: broadcast failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------------- phones */

    /**
     * Ring the phones of the agents being rung.
     *
     * Data-only on Android, so the app can draw a real incoming-call screen
     * instead of a notification; an alert on iOS, which delivers nothing else
     * to an app that is not running. The page in the app's WebView hears the
     * same call over the socket when it is open — the app ignores the push
     * while it is in the foreground (docs/mobile-push.md).
     *
     * @param  Collection<int, User>  $agents
     */
    private function pushRing(Call $call, Collection $agents): void
    {
        $this->pushTo($call, $agents->pluck('id')->all(), function (DeviceToken $device, PushNotifier $notifier) use ($call) {
            $contact = $this->contactName($call, $notifier, $device);
            $expires = ($call->ringing_at ?? now())->timestamp + 60;

            return [
                'data' => [
                    'type' => 'incoming_call',
                    'call_id' => (string) $call->id,
                    'conversation_id' => (string) $call->conversation_id,
                    'connection_id' => (string) $call->connection_id,
                    'contact_name' => $contact,
                    'connection_name' => (string) $call->connection?->name,
                    'expires_at' => (string) $expires,
                    'url' => '/conversations?conversation='.$call->conversation_id.'&call='.$call->id,
                ],
                'android' => ['priority' => 'high', 'ttl' => '60s'],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                        'apns-collapse-id' => 'call-'.$call->id,
                        'apns-expiration' => (string) $expires,
                    ],
                    'payload' => ['aps' => [
                        'alert' => ['title' => $contact, 'body' => $notifier->lineFor($device, 'incoming_call')],
                        'sound' => 'default',
                        'thread-id' => 'call-'.$call->id,
                        'interruption-level' => 'time-sensitive',
                    ]],
                ],
            ];
        });
    }

    /**
     * Stop the ring on the phones that were ringing: on Android the app
     * dismisses its call screen; on iOS the alert is replaced (same collapse
     * id) by one that says how it went.
     *
     * @param  array<string, string>  $params
     */
    private function pushStop(Call $call, string $line, array $params = [], ?int $exceptUserId = null): void
    {
        $ids = array_values(array_diff(array_map('intval', $call->rung_user_ids ?? []), [(int) $exceptUserId]));

        $this->pushTo($call, $ids, function (DeviceToken $device, PushNotifier $notifier) use ($call, $line, $params) {
            return [
                'data' => [
                    'type' => 'call_cancelled',
                    'call_id' => (string) $call->id,
                    'conversation_id' => (string) $call->conversation_id,
                    'status' => $call->status->value,
                    'agent' => (string) ($params['agent'] ?? ''),
                    'url' => '/conversations?conversation='.$call->conversation_id,
                ],
                'android' => ['priority' => 'high', 'ttl' => '60s'],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '5',
                        'apns-push-type' => 'alert',
                        'apns-collapse-id' => 'call-'.$call->id,
                    ],
                    'payload' => ['aps' => [
                        'alert' => [
                            'title' => $this->contactName($call, $notifier, $device),
                            'body' => $notifier->lineFor($device, $line, $params),
                        ],
                        'thread-id' => 'call-'.$call->id,
                    ]],
                ],
            ];
        });
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  callable(DeviceToken, PushNotifier): array<string, mixed>  $build
     */
    private function pushTo(Call $call, array $userIds, callable $build): void
    {
        try {
            if ($userIds === [] || ! config('push.enabled') || ! FirebaseConfig::isConfigured()) {
                return;
            }

            $notifier = app(PushNotifier::class);

            DeviceToken::query()
                ->where('tenant_id', $call->tenant_id)
                ->whereIn('user_id', $userIds)
                ->with('user.tenant.market')
                ->get()
                ->each(fn (DeviceToken $device) => SendPushNotification::dispatch($device->id, $build($device, $notifier))
                    ->onQueue(config('calls.push_queue')));
        } catch (Throwable $e) {
            Log::warning('Call: push failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    private function contactName(Call $call, PushNotifier $notifier, DeviceToken $device): string
    {
        $contact = $call->getRelationValue('contact') ?? $call->contact()->first();
        $identity = ContactIdentity::for($contact);

        return ContactIdentity::name($contact) ?? ($identity['phone'] ?? $notifier->lineFor($device, 'a_contact'));
    }
}
