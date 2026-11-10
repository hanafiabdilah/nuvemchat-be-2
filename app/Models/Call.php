<?php

namespace App\Models;

use App\Enums\Call\CallStatus;
use App\Enums\Connection\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One voice call answered (or offered to be answered) from the dashboard.
 *
 * The live half of a call. Its record for readers is the CallLog note in the
 * thread (`message_id`), which outlives this row's interest: once a call ends
 * nothing here is read again except by statistics.
 *
 * @property CallStatus $status
 */
class Call extends Model
{
    /** Nobody was free to ring when it came in. */
    public const REASON_NO_AGENT = 'no_agent';

    /** Everybody who was rung pressed Decline. */
    public const REASON_DECLINED = 'declined';

    /** It rang out: Meta gave up waiting for an answer. */
    public const REASON_UNANSWERED = 'unanswered';

    public const REASON_CUSTOMER_HUNG_UP = 'customer_hung_up';

    public const REASON_AGENT_HUNG_UP = 'agent_hung_up';

    /** The answering browser stopped reporting in mid-call. */
    public const REASON_LOST = 'lost';

    /** Meta refused to connect it, or the media never came up. */
    public const REASON_FAILED = 'failed';

    /** Our own sweep closed it: the webhook that should have never arrived. */
    public const REASON_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'conversation_id',
        'contact_id',
        'message_id',
        'channel',
        'external_id',
        'direction',
        'status',
        'end_reason',
        'offer_sdp',
        'answer_sdp',
        'answered_by_user_id',
        'rung_user_ids',
        'declined_user_ids',
        'ringing_at',
        'claimed_at',
        'answered_at',
        'ended_at',
        'last_heartbeat_at',
        'duration_seconds',
        'meta',
    ];

    /** The SDPs are setup material, never something a resource should echo. */
    protected $hidden = ['offer_sdp', 'answer_sdp'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => CallStatus::class,
            'rung_user_ids' => 'array',
            'declined_user_ids' => 'array',
            'ringing_at' => 'datetime',
            'claimed_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), CallStatus::liveValues());
    }

    /** The calls a person may see: on connections they can reach. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where($query->qualifyColumn('tenant_id'), $user->tenant_id);

        if (! $user->canAccessAllConnections()) {
            $query->whereIn($query->qualifyColumn('connection_id'), $user->accessibleConnectionIds() ?: [0]);
        }

        return $query;
    }

    /** Whether this person is among those being rung right now. */
    public function isRinging(User $user): bool
    {
        return $this->status === CallStatus::Ringing
            && in_array((int) $user->id, array_map('intval', $this->rung_user_ids ?? []), true)
            && ! in_array((int) $user->id, array_map('intval', $this->declined_user_ids ?? []), true);
    }

    /** Seconds the call has been running, or how long it ran. */
    public function elapsedSeconds(): ?int
    {
        if (! $this->answered_at) {
            return null;
        }

        return (int) $this->answered_at->diffInSeconds($this->ended_at ?? now(), absolute: true);
    }
}
