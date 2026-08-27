<?php

namespace App\Services\Live;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\User;
use App\Support\SqlDialect;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The live monitor: who is at their desk right now, and what is moving through
 * the channels this second.
 *
 * Every other analytics surface in this codebase answers "what happened over a
 * period" — it compares a range with the range before it, and none of its
 * numbers change while you look at them. That is the wrong instrument for the
 * question a supervisor actually asks during a shift: is anyone covering the
 * queue, and is traffic still flowing. This class answers only that, and only
 * about the last few minutes.
 *
 * It is deliberately **metadata-only**. The feed says a text message arrived
 * from a contact on a WhatsApp connection and that an agent answered it four
 * seconds later; it never carries a single character of what either of them
 * wrote. That keeps one implementation honest on both surfaces: the Back
 * Office reads the same rows as the tenant dashboard, and platform staff watching
 * a wallboard are not quietly reading other companies' customer conversations.
 * The audited path for actually looking at a thread is still impersonation.
 *
 * Scoping is fixed at construction and nothing here reaches around it:
 * {@see forUser()} narrows to a tenant *and* to the connections that user was
 * assigned (an agent with one inbox must not watch the rest of the workspace
 * stream past), {@see forPlatform()} spans every tenant or one named tenant.
 */
final class LiveMonitor
{
    /** Feed rows returned per call. A wallboard cannot read more than this. */
    public const FEED_LIMIT = 60;

    public const MAX_FEED_LIMIT = 200;

    /** How far back the counters and the sparkline look. */
    public const WINDOW_MINUTES = 15;

    /**
     * An agent whose dashboard is still beating but who has sent nothing for
     * this long reads as "present, not typing" rather than as working. The
     * heartbeat itself only proves the tab is open.
     */
    private const IDLE_AFTER_SECONDS = 300;

    /** A pending thread older than this is a queue, not a blip. */
    private const WAITING_ALERT_SECONDS = 600;

    /**
     * How recently a thread must have moved to still count as queue depth.
     *
     * Same bound, same reason, as OverviewStats::QUEUE_ACTIVE_DAYS — see the
     * comment on inboxConversations() for why an unbounded count of open
     * threads is the wrong number for a board about right now.
     */
    private const QUEUE_ACTIVE_DAYS = 7;

    /**
     * How long an aggregate is reused.
     *
     * The stream itself is never cached — it pages by primary key and is the
     * part that has to be instant. These are the other half: counters and a
     * roster that scan whole tables, asked for on the slow tick. Without this,
     * two operations people with the wallboard open on a second monitor means
     * twice the full scans, and ten means ten. Five seconds against a ten
     * second refresh is invisible to a reader and collapses every open tab onto
     * one computation.
     */
    private const AGGREGATE_TTL_SECONDS = 5;

    /**
     * The feed never reaches further back than this on a cold load. A monitor
     * opened after a quiet night should show an empty stream, not yesterday.
     */
    private const COLD_START_HOURS = 6;

    /**
     * Activity rows returned per call. These are human actions — a shift
     * produces a handful a minute at most, so the lane is read whole rather
     * than paged.
     */
    private const ACTIVITY_LIMIT = 40;

    /**
     * Which inbox the board is about. Same three values, same meaning and the
     * same predicate as StatsScope — chat and e-mail are measured apart there
     * because they behave nothing alike (a chat is answered in seconds by an
     * assignee, an e-mail in hours by a shared inbox with none), and mixing
     * them produces a middle number that describes neither. That is at least as
     * true of a live board: one queue is late at four minutes, the other is
     * fine at four hours.
     */
    public const SCOPE_ALL = 'all';

    public const SCOPE_CHAT = 'chat';

    public const SCOPE_EMAIL = 'email';

    private function __construct(
        private readonly ?int $tenantId,
        /** @var int[]|null Null means every connection in scope. */
        private readonly ?array $connectionIds,
        private readonly bool $maskContacts,
        private readonly bool $withTenant,
        private readonly string $scope,
    ) {}

    /** The three inbox scopes, for request validation. */
    public static function scopes(): array
    {
        return [self::SCOPE_ALL, self::SCOPE_CHAT, self::SCOPE_EMAIL];
    }

    /**
     * The tenant dashboard's view: one workspace, and only the inboxes this
     * user holds. Owners hold no `connection_user` rows by design, so they are
     * resolved through the role instead — see User::canAccessAllConnections().
     */
    public static function forUser(User $user, string $scope = self::SCOPE_ALL): self
    {
        return new self(
            tenantId: (int) $user->tenant_id,
            connectionIds: $user->canAccessAllConnections() ? null : $user->accessibleConnectionIds(),
            maskContacts: false,
            withTenant: false,
            scope: $scope,
        );
    }

    /**
     * The Back Office view: every workspace, or one when a tenant is named.
     * Contact identifiers are masked here — the phone number belongs to
     * somebody who is a customer of a customer, and operations can act on all
     * of this without it.
     */
    public static function forPlatform(?int $tenantId = null, string $scope = self::SCOPE_ALL): self
    {
        return new self(
            tenantId: $tenantId,
            connectionIds: null,
            maskContacts: true,
            withTenant: true,
            scope: $scope,
        );
    }

    /* ------------------------------------------------------------ the feed */

    /**
     * Message traffic as a stream, oldest first.
     *
     * `$afterId` is the whole reason this reads as live rather than as a table
     * that redraws: callers poll with the last id they hold and get back only
     * what happened since, so the client appends instead of replacing and
     * nothing on screen jumps. Paging by primary key rather than by timestamp
     * is also what makes a poll every few seconds cheap — and it cannot skip a
     * row that was inserted a millisecond after the previous cutoff.
     *
     * System notes (MessageType::Info — transfers, expired windows, call logs)
     * are excluded. They are written Outgoing but never sent to a channel, so
     * they would appear in the outbound lane as messages nobody sent.
     */
    public function feed(?int $afterId = null, int $limit = self::FEED_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_FEED_LIMIT));

        $query = $this->messages()
            ->leftJoin('contacts', 'conversations.contact_id', '=', 'contacts.id')
            ->leftJoin('users as actors', 'messages.sent_by_user_id', '=', 'actors.id')
            ->leftJoin('flows', 'messages.sent_by_flow_id', '=', 'flows.id')
            ->leftJoin('ai_hub_agents', 'messages.sent_by_ai_hub_agent_id', '=', 'ai_hub_agents.id')
            ->leftJoin('contacts as senders', 'messages.contact_id', '=', 'senders.id')
            ->where('messages.message_type', '!=', MessageType::Info->value)
            ->select([
                'messages.id',
                'messages.created_at',
                'messages.sender_type',
                'messages.message_type',
                'messages.error',
                'messages.delivery_at',
                'messages.read_at',
                'conversations.id as conversation_id',
                'conversations.type as conversation_type',
                'connections.id as connection_id',
                'connections.name as connection_name',
                'connections.channel as channel',
                'connections.tenant_id as tenant_id',
                'contacts.name as contact_name',
                'contacts.external_id as contact_external_id',
                'senders.name as sender_name',
                'actors.name as agent_name',
                'flows.name as flow_name',
                'ai_hub_agents.name as ai_agent_name',
            ]);

        if ($afterId !== null) {
            // A delta: everything since the caller's cursor, oldest first, so
            // a burst arrives in the order it happened.
            $rows = $query->where('messages.id', '>', $afterId)
                ->orderBy('messages.id')
                ->limit($limit)
                ->get();
        } else {
            // A cold load: the tail of recent traffic, flipped back into
            // chronological order once the newest N are in hand.
            $rows = $query->where('messages.created_at', '>=', now()->subHours(self::COLD_START_HOURS))
                ->orderByDesc('messages.id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
        }

        return $rows->map(fn ($row) => $this->event($row))->all();
    }

    private function event(object $row): array
    {
        $incoming = $row->sender_type === SenderType::Incoming->value;

        $event = [
            'id' => (int) $row->id,
            'at' => Carbon::parse($row->created_at)->toIso8601String(),
            'direction' => $incoming ? 'in' : 'out',
            'message_type' => $row->message_type,
            'conversation_id' => (int) $row->conversation_id,
            'is_group' => (bool) ($row->conversation_type === 'group'),
            'channel' => $row->channel,
            'connection' => [
                'id' => (int) $row->connection_id,
                'name' => $row->connection_name,
            ],
            'contact' => [
                'name' => $this->contactLabel($row),
                'handle' => $this->handle($row->contact_external_id),
            ],
            'actor' => $this->actor($row, $incoming),
            'status' => $this->status($row, $incoming),
        ];

        if ($this->withTenant) {
            $event['tenant_id'] = (int) $row->tenant_id;
        }

        return $event;
    }

    /**
     * Who put this message on the wire.
     *
     * Inbound it is the person on the other end — in a group that is the
     * member who spoke, which is a different contact from the thread's own.
     * Outbound the four possibilities are worth telling apart on a wallboard:
     * a message an agent typed, one the AI answered, one a flow fired, and one
     * with no author at all (an accept greeting, a campaign) that would
     * otherwise be misread as somebody's work.
     */
    private function actor(object $row, bool $incoming): array
    {
        if ($incoming) {
            return [
                'kind' => 'contact',
                'name' => $row->sender_name ?: $this->contactLabel($row),
            ];
        }

        if ($row->agent_name !== null) {
            return ['kind' => 'agent', 'name' => $row->agent_name];
        }

        if ($row->ai_agent_name !== null) {
            return ['kind' => 'ai', 'name' => $row->ai_agent_name];
        }

        if ($row->flow_name !== null) {
            return ['kind' => 'flow', 'name' => $row->flow_name];
        }

        return ['kind' => 'system', 'name' => null];
    }

    /**
     * Delivery state, best known. Only outbound rows have one: an inbound
     * message is by definition already delivered to us.
     */
    private function status(object $row, bool $incoming): ?string
    {
        if ($incoming) {
            return null;
        }

        if ($row->error !== null) {
            return 'failed';
        }

        if ($row->read_at !== null) {
            return 'read';
        }

        return $row->delivery_at !== null ? 'delivered' : 'sent';
    }

    private function contactLabel(object $row): ?string
    {
        $name = $row->contact_name;

        if ($name === null || $name === '') {
            return null;
        }

        // An unnamed contact is stored under its own external id, which for
        // WhatsApp is the phone number — mask it like one rather than letting
        // it through as a "name".
        if ($name === $row->contact_external_id) {
            return $this->handle($name);
        }

        return $this->maskContacts ? self::maskName($name) : $name;
    }

    private function handle(?string $externalId): ?string
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return $this->maskContacts ? self::maskHandle($externalId) : $externalId;
    }

    /**
     * "João Pereira" -> "João P." — enough for an operator to tell two rows
     * apart and to describe one over the phone, not enough to be a contact
     * list of another company's customers.
     */
    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        return $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }

    /**
     * Phone numbers, e-mail addresses and channel-scoped ids all end up here.
     * The last four characters survive because that is what someone reads back
     * when correlating with a customer's own report; everything identifying in
     * front of them does not.
     */
    private static function maskHandle(string $handle): string
    {
        if (str_contains($handle, '@') && ! str_ends_with($handle, '@g.us')) {
            [$local, $domain] = explode('@', $handle, 2);

            return mb_substr($local, 0, 1).'•••@'.$domain;
        }

        $length = mb_strlen($handle);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', min(6, $length - 4)).mb_substr($handle, -4);
    }

    /* -------------------------------------------------------- the activity */

    /**
     * What people did to conversations, newest first.
     *
     * The third lane, and the one that explains the other two: a thread going
     * quiet means nothing on its own, but "Ana took it over, Bruno resolved it"
     * is the shift actually happening. Handoffs belong here for the opposite
     * reason — that is work arriving, not work finishing.
     *
     * Read whole rather than paged by cursor, unlike the message stream. These
     * are human actions: a busy shift produces a few a minute, so re-reading a
     * capped window costs less than the machinery to page it. Two of the three
     * sources could not be paged by message id anyway — a resolution is a
     * column changing on a conversation, not a row being written.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $limit = self::ACTIVITY_LIMIT): array
    {
        return $this->cached('activity.'.$limit, function () use ($limit) {
            $since = now()->subMinutes(self::WINDOW_MINUTES);

            // Resolutions used to be read from `conversations.resolved_at`
            // here. They are not any more: every status change now writes a
            // note into the thread (ConversationObserver::noteStatusChange), so
            // reading the column as well would report one closure twice.
            // Handoffs still need their own source — in practice they move a
            // thread from Pending to Pending, which is no status change at all.
            $rows = array_merge(
                $this->threadNotes($since, $limit),
                $this->handoffs($since, $limit),
            );

            usort($rows, fn (array $a, array $b) => $b['at_ts'] <=> $a['at_ts']);

            return array_map(function (array $row) {
                unset($row['at_ts']);

                return $row;
            }, array_slice($rows, 0, $limit));
        });
    }

    /**
     * Notes the platform already writes into threads: transfers, take-overs,
     * an agent picking a thread out of the queue, a reply window closing, a
     * call. They are real `messages` rows carrying a stable code plus the
     * values its copy needs, so nothing new had to be recorded for this lane —
     * see App\Services\Conversation\SystemMessage.
     *
     * They are excluded from the message stream on purpose (they are written
     * Outgoing but never sent to a channel, so the outbound lane would show
     * them as messages nobody sent); this is where they belong instead.
     */
    private function threadNotes(Carbon $since, int $limit): array
    {
        return $this->messages()
            ->leftJoin('contacts', 'conversations.contact_id', '=', 'contacts.id')
            ->where('messages.message_type', MessageType::Info->value)
            ->where('messages.created_at', '>=', $since)
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->select([
                'messages.id',
                'messages.created_at',
                'messages.body',
                'messages.meta',
                ...self::CONTEXT_COLUMNS,
            ])
            ->get()
            ->map(function ($row) {
                // Hand-decoded rather than cast: this is the query builder, not
                // Eloquent, so `meta` arrives as the raw column — and a row with
                // no meta at all is normal here.
                $meta = json_decode((string) $row->meta, true);
                $info = is_array($meta) && is_array($meta['info'] ?? null) ? $meta['info'] : [];

                return $this->activityRow(
                    id: 'note:'.$row->id,
                    at: Carbon::parse($row->created_at),
                    // A note from before codes existed still reads, through its
                    // stored English body.
                    code: $info['code'] ?? null,
                    params: is_array($info['params'] ?? null) ? $info['params'] : [],
                    body: $row->body,
                    row: $row,
                );
            })
            ->all();
    }

    /**
     * Threads the automation handed to a person.
     *
     * The one kind of activity that is a request rather than a report: every
     * row here is somebody waiting for an agent who has not arrived yet.
     */
    private function handoffs(Carbon $since, int $limit): array
    {
        return $this->conversations()
            ->leftJoin('contacts', 'conversations.contact_id', '=', 'contacts.id')
            ->whereNotNull('conversations.handoff_at')
            ->where('conversations.handoff_at', '>=', $since)
            ->orderByDesc('conversations.handoff_at')
            ->limit($limit)
            ->select(['conversations.handoff_at', 'conversations.handoff_reason', ...self::CONTEXT_COLUMNS])
            ->get()
            ->map(fn ($row) => $this->activityRow(
                id: 'handoff:'.$row->conversation_id.':'.Carbon::parse($row->handoff_at)->timestamp,
                at: Carbon::parse($row->handoff_at),
                code: 'conversation_handoff',
                params: array_filter(['reason' => $row->handoff_reason]),
                body: 'Handed to a human agent.',
                row: $row,
            ))
            ->all();
    }

    /**
     * The thread columns every activity source needs, so the three of them
     * return rows the renderer can treat identically.
     */
    private const CONTEXT_COLUMNS = [
        'conversations.id as conversation_id',
        'connections.id as connection_id',
        'connections.name as connection_name',
        'connections.channel as channel',
        'connections.tenant_id as tenant_id',
        'contacts.name as contact_name',
        'contacts.external_id as contact_external_id',
    ];

    /**
     * One activity row.
     *
     * `code` and `params` rather than a finished sentence: the server has no
     * idea what language the reader uses, and a duration or a name formatted
     * here would be frozen into one. `body` is the English fallback for a code
     * the client has not learned yet — the same contract the thread notes
     * already use, so both surfaces render this with the copy they have.
     */
    private function activityRow(string $id, Carbon $at, ?string $code, array $params, ?string $body, object $row): array
    {
        $entry = [
            'id' => $id,
            'at' => $at->toIso8601String(),
            'at_ts' => $at->getTimestamp(),
            'code' => $code,
            'params' => (object) $params,
            'body' => $body,
            'conversation_id' => (int) $row->conversation_id,
            'channel' => $row->channel,
            'connection' => [
                'id' => (int) $row->connection_id,
                'name' => $row->connection_name,
            ],
            'contact' => [
                'name' => $this->contactLabel($row),
                'handle' => $this->handle($row->contact_external_id),
            ],
        ];

        if ($this->withTenant) {
            $entry['tenant_id'] = (int) $row->tenant_id;
        }

        return $entry;
    }

    /* --------------------------------------------------------- the counters */

    /**
     * The numbers along the top: is traffic flowing, is anyone waiting, is
     * anything failing. All of them describe the last few minutes only.
     */
    public function pulse(): array
    {
        return $this->cached('pulse', function () {
            $window = now()->subMinutes(self::WINDOW_MINUTES);

            $volume = $this->messages()
                ->where('messages.message_type', '!=', MessageType::Info->value)
                ->where('messages.created_at', '>=', $window)
                ->selectRaw(
                    'SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                     SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as outbound,
                     SUM(CASE WHEN messages.sender_type = ? AND messages.error IS NOT NULL THEN 1 ELSE 0 END) as failed',
                    [SenderType::Incoming->value, SenderType::Outgoing->value, SenderType::Outgoing->value]
                )
                ->first();

            $minute = now()->subMinute();

            $recent = $this->messages()
                ->where('messages.message_type', '!=', MessageType::Info->value)
                ->where('messages.created_at', '>=', $minute)
                ->selectRaw(
                    'SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                     SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as outbound',
                    [SenderType::Incoming->value, SenderType::Outgoing->value]
                )
                ->first();

            $byStatus = $this->inboxConversations()
                ->selectRaw('conversations.status as status, COUNT(*) as c')
                ->groupBy('conversations.status')
                ->pluck('c', 'status');

            $waiting = $this->inboxConversations()
                ->where('conversations.status', Status::Pending->value)
                ->selectRaw('COUNT(*) as total,
                             SUM(CASE WHEN conversations.user_id IS NULL THEN 1 ELSE 0 END) as unassigned,
                             MIN(conversations.last_message_at) as oldest')
                ->first();

            $oldestWaiting = $waiting?->oldest
                ? (int) now()->diffInSeconds(Carbon::parse($waiting->oldest), absolute: true)
                : null;

            return [
                'window_minutes' => self::WINDOW_MINUTES,
                'inbound_60s' => (int) ($recent->inbound ?? 0),
                'outbound_60s' => (int) ($recent->outbound ?? 0),
                'inbound_window' => (int) ($volume->inbound ?? 0),
                'outbound_window' => (int) ($volume->outbound ?? 0),
                'failed_window' => (int) ($volume->failed ?? 0),
                'active_conversations' => (int) ($byStatus[Status::Active->value] ?? 0),
                'ai_handling' => (int) ($byStatus[Status::AiHandling->value] ?? 0),
                'waiting' => (int) ($waiting->total ?? 0),
                'waiting_unassigned' => (int) ($waiting->unassigned ?? 0),
                'oldest_waiting_seconds' => $oldestWaiting,
                'waiting_alert_seconds' => self::WAITING_ALERT_SECONDS,
                'needs_human' => $this->inboxConversations()->where('conversations.needs_human', true)->count(),
                'series' => $this->series($window),
            ];
        });
    }

    /**
     * One bucket per minute across the window, zero-filled.
     *
     * Zero-filling in PHP rather than trusting the rows is the point: a quiet
     * minute has no row to return, and a sparkline that silently closes those
     * gaps draws steady traffic through an outage.
     */
    private function series(Carbon $from): array
    {
        $expr = SqlDialect::minute('messages.created_at');

        $rows = $this->messages()
            ->where('messages.message_type', '!=', MessageType::Info->value)
            ->where('messages.created_at', '>=', $from)
            ->selectRaw(
                "{$expr} as bucket,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as outbound",
                [SenderType::Incoming->value, SenderType::Outgoing->value]
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $out = [];
        $cursor = now()->utc()->subMinutes(self::WINDOW_MINUTES - 1)->startOfMinute();

        for ($i = 0; $i < self::WINDOW_MINUTES; $i++) {
            $key = $cursor->format('Y-m-d H:i');

            $out[] = [
                'at' => $cursor->toIso8601String(),
                'inbound' => (int) ($rows[$key]->inbound ?? 0),
                'outbound' => (int) ($rows[$key]->outbound ?? 0),
            ];

            $cursor = $cursor->copy()->addMinute();
        }

        return $out;
    }

    /* ----------------------------------------------------------- the people */

    /**
     * Who is staffing the inbox, and what each of them is on.
     *
     * Presence comes from `users.last_seen_at`, which only the SPA heartbeat
     * writes — not from request traffic (an agent reading a long thread sends
     * nothing for minutes) and not from socket state (only Reverb knows that).
     * A null is therefore offline, which is the safe direction: a deployment
     * whose frontend has not shipped yet shows an empty roster rather than
     * inventing a staffed one.
     *
     * `$onlineOnly` is what makes this usable platform-wide — the Back Office
     * wants the handful of people currently working across every workspace,
     * while a supervisor inside one tenant also needs to see who is missing.
     */
    public function agents(bool $onlineOnly = false, int $limit = 100): array
    {
        return $this->cached('agents.'.(int) $onlineOnly.'.'.$limit, function () use ($onlineOnly, $limit) {
            $onlineSince = now()->subSeconds((int) config('presence.online_seconds', 150));

            $users = DB::table('users')
                ->when($this->tenantId, fn ($q) => $q->where('users.tenant_id', $this->tenantId))
                ->whereNotNull('users.tenant_id')
                ->when($onlineOnly, fn ($q) => $q->where('users.last_seen_at', '>=', $onlineSince))
                ->select(['users.id', 'users.name', 'users.email', 'users.tenant_id', 'users.last_seen_at'])
                ->orderByDesc('users.last_seen_at')
                ->limit($limit)
                ->get();

            if ($users->isEmpty()) {
                return [];
            }

            $ids = $users->pluck('id')->all();

            $open = $this->inboxConversations()
                ->whereIn('conversations.user_id', $ids)
                ->where('conversations.status', Status::Active->value)
                ->selectRaw('conversations.user_id as user_id, COUNT(*) as c')
                ->groupBy('conversations.user_id')
                ->pluck('c', 'user_id');

            $handling = $this->lastSentPerAgent($ids);

            return $users->map(function ($user) use ($onlineSince, $open, $handling) {
                $lastSeen = $user->last_seen_at ? Carbon::parse($user->last_seen_at) : null;
                $online = $lastSeen !== null && $lastSeen->gte($onlineSince);
                $recent = $handling[$user->id] ?? null;

                $sentSecondsAgo = $recent
                    ? (int) now()->diffInSeconds(Carbon::parse($recent->created_at), absolute: true)
                    : null;

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $this->withTenant ? null : $user->email,
                    'tenant_id' => (int) $user->tenant_id,
                    'presence' => match (true) {
                        ! $online => 'offline',
                        $sentSecondsAgo !== null && $sentSecondsAgo <= self::IDLE_AFTER_SECONDS => 'active',
                        default => 'online',
                    },
                    'last_seen_seconds' => $lastSeen ? (int) now()->diffInSeconds($lastSeen, absolute: true) : null,
                    'open_conversations' => (int) ($open[$user->id] ?? 0),
                    'last_sent_seconds' => $sentSecondsAgo,
                    'handling' => $recent ? [
                        'conversation_id' => (int) $recent->conversation_id,
                        'contact' => $this->contactLabel($recent),
                        'channel' => $recent->channel,
                        'connection' => $recent->connection_name,
                    ] : null,
                ];
            })->all();
        });
    }

    /**
     * The most recent message each of these agents sent, with just enough of
     * its thread to name what they are working on.
     *
     * Two queries rather than one per agent: the ids first (a grouped MAX over
     * the primary key), then one pass to hydrate them. `MIN`/`MAX` on `id` is
     * used throughout the analytics code in place of the timestamp — it is the
     * insertion order, and unlike `sent_at` it cannot be back-dated by a
     * history import.
     */
    private function lastSentPerAgent(array $userIds): array
    {
        $latest = DB::table('messages')
            ->whereIn('messages.sent_by_user_id', $userIds)
            ->where('messages.created_at', '>=', now()->subHours(self::COLD_START_HOURS))
            ->selectRaw('messages.sent_by_user_id as user_id, MAX(messages.id) as id')
            ->groupBy('messages.sent_by_user_id')
            ->pluck('id', 'user_id');

        if ($latest->isEmpty()) {
            return [];
        }

        return $this->messages()
            ->leftJoin('contacts', 'conversations.contact_id', '=', 'contacts.id')
            ->whereIn('messages.id', $latest->values()->all())
            ->select([
                'messages.id',
                'messages.sent_by_user_id',
                'messages.created_at',
                'conversations.id as conversation_id',
                'connections.name as connection_name',
                'connections.channel as channel',
                'contacts.name as contact_name',
                'contacts.external_id as contact_external_id',
            ])
            ->get()
            ->keyBy('sent_by_user_id')
            ->all();
    }

    /**
     * Delivery states that changed *after* the message was inserted.
     *
     * The stream pages by primary key, which is what makes it cheap — but a
     * receipt does not create a row, it edits one. Without this, every send
     * would sit on screen marked "sent" forever and the tick marks would be
     * decoration. So each full tick also carries a small patch list: the client
     * applies it to whatever rows it still holds by id and drops the rest.
     *
     * Scoped to outbound rows in the window that have any state beyond "sent".
     * Comparing `updated_at` against `created_at` would be the tighter filter
     * and is wrong: both are stored to the second, so a receipt arriving in the
     * same second as the send is indistinguishable from no receipt at all, and
     * that row would sit marked "sent" for as long as anyone watched it.
     */
    public function statusUpdates(int $limit = 200): array
    {
        return $this->cached('status.'.$limit, fn () => $this->messages()
            ->where('messages.created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->where('messages.sender_type', SenderType::Outgoing->value)
            ->where('messages.message_type', '!=', MessageType::Info->value)
            ->where(fn ($q) => $q
                ->whereNotNull('messages.error')
                ->orWhereNotNull('messages.delivery_at')
                ->orWhereNotNull('messages.read_at'))
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->select(['messages.id', 'messages.error', 'messages.delivery_at', 'messages.read_at'])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'status' => $this->status($row, incoming: false),
            ])
            ->all());
    }

    /**
     * Which workspaces are actually moving right now, busiest first.
     *
     * Only the Back Office asks this, and it earns its place twice: it is the
     * page's tenant filter (a list of every customer would be a list of mostly
     * silent ones) and it is the answer to "where is the load coming from"
     * during an incident.
     */
    public function activeTenants(int $limit = 12): array
    {
        return $this->cached('tenants.'.$limit, fn () => $this->messages()
            ->where('messages.message_type', '!=', MessageType::Info->value)
            ->where('messages.created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->selectRaw(
                'connections.tenant_id as tenant_id,
                 COUNT(*) as messages,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound',
                [SenderType::Incoming->value]
            )
            ->groupBy('connections.tenant_id')
            ->orderByDesc('messages')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'tenant_id' => (int) $row->tenant_id,
                'messages' => (int) $row->messages,
                'inbound' => (int) $row->inbound,
                'outbound' => (int) $row->messages - (int) $row->inbound,
            ])
            ->all());
    }

    /* ------------------------------------------------------------- plumbing */

    /**
     * Reuse an aggregate across everyone watching the same scope.
     *
     * The key carries the scope, not the viewer: two agents holding the same
     * inboxes are asking the same question and should share the answer, while a
     * third with a different set must never see theirs. That is what the
     * connection-id hash is for — dropping it would make this a cross-inbox
     * leak rather than a cache.
     */
    private function cached(string $bucket, \Closure $resolve): array
    {
        $scope = ($this->tenantId ?? 'all').':'.(
            $this->connectionIds === null ? 'all' : md5(implode(',', $this->connectionIds))
        ).':'.($this->maskContacts ? 'masked' : 'plain').':'.$this->scope;

        return Cache::remember("live:{$bucket}:{$scope}", self::AGGREGATE_TTL_SECONDS, $resolve);
    }

    /**
     * Every read starts here, so tenant scoping, the per-agent connection
     * filter and the inbox split cannot be forgotten by one query and
     * remembered by the next — the same reason StatsScope exists for the
     * period-based statistics.
     */
    private function messages(): Builder
    {
        return $this->scoped(
            DB::table('messages')
                ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
                ->join('connections', 'conversations.connection_id', '=', 'connections.id')
        );
    }

    private function conversations(): Builder
    {
        return $this->scoped(
            DB::table('conversations')
                ->join('connections', 'conversations.connection_id', '=', 'connections.id')
        );
    }

    /**
     * Conversations as a shift could actually act on them: in the inbox, and
     * moved recently. Every queue-depth counter goes through here; the
     * activity lanes do not, because a thread that was resolved or handed off
     * carries its own timestamp and cannot be one of the rows this excludes.
     *
     * The three exclusions are the same ones OverviewStats settled on, and
     * each closes a way this board was reporting work nobody can do:
     *
     * A conversation with no message at all is not waiting for anybody. The
     * Live Chat Widget opens one the moment a visitor loads the page, before
     * they type a word — so every bounce landed in "Waiting" while being
     * invisible in every agent's list. One production tenant reached 4,591
     * such rows, growing by fifty to a hundred a day, against three real
     * threads. The inbox has always filtered these — see the whereHas on
     * messages in ConversationController::index() — and this surface never
     * did, which is exactly why the two disagreed.
     *
     * A removed group is gone from the panel by definition.
     *
     * And nothing drains Pending on its own — only Accept, Resolve, or the AI
     * picking a thread up, and the window-expiry closer skips Pending on
     * purpose — so without a recency bound the count is a permanent backlog
     * reaching back to the workspace's first day. Older threads are not lost:
     * they stay in the inbox, and the period metrics in Statistics still count
     * them. They are simply not news on a board about the last few minutes.
     */
    private function inboxConversations(): Builder
    {
        return $this->conversations()
            ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id'))
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('contacts')
                ->whereColumn('contacts.id', 'conversations.contact_id')
                ->whereNotNull('contacts.group_removed_at'))
            ->where('conversations.last_message_at', '>=', now()->subDays(self::QUEUE_ACTIVE_DAYS));
    }

    /** The three narrowings, applied to any query that has reached `connections`. */
    private function scoped(Builder $query): Builder
    {
        return $query
            ->when($this->tenantId, fn ($q) => $q->where('connections.tenant_id', $this->tenantId))
            ->when($this->connectionIds !== null, fn ($q) => $q->whereIn('conversations.connection_id', $this->connectionIds ?: [0]))
            ->when($this->scope === self::SCOPE_CHAT, fn ($q) => $q->where('connections.channel', '!=', Channel::Email->value))
            ->when($this->scope === self::SCOPE_EMAIL, fn ($q) => $q->where('connections.channel', Channel::Email->value));
    }

    /**
     * The id a client should send back as its cursor. Taken from the feed when
     * it returned anything, and otherwise from the table's own head — without
     * that, a monitor opened during a quiet spell would start at zero and then
     * replay the entire history on its first delta.
     */
    public function cursorFor(array $events): int
    {
        if ($events !== []) {
            return (int) end($events)['id'];
        }

        return (int) (DB::table('messages')->max('id') ?? 0);
    }
}
