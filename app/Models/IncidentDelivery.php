<?php

namespace App\Models;

use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliverySource;
use App\Enums\Incident\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One conversation's outcome for one incident — including the decision not to
 * tell it (`not_matched`). Unique per (incident, conversation, kind).
 *
 * @property DeliveryKind $kind
 * @property DeliveryStatus $status
 * @property DeliverySource $source
 */
class IncidentDelivery extends Model
{
    public const REASON_CONVERSATION_GONE = 'conversation_gone';

    public const REASON_INCIDENT_OVER = 'incident_over';

    public const REASON_CONVERSATION_RESOLVED = 'conversation_resolved';

    public const REASON_OPTED_OUT = 'opted_out';

    public const REASON_WINDOW_CLOSED = 'window_closed';

    /** Claimed by a send whose worker died before it said how it went. Never re-sent. */
    public const REASON_INTERRUPTED = 'interrupted';

    public const REASON_SEND_FAILED = 'send_failed';

    /**
     * Sent, but with the fixed message: Mode IA could not write it. On a `sent`
     * row these say why the customer got the words as written.
     */
    public const REASON_AI_UNAVAILABLE = 'ai_unavailable';

    public const REASON_AI_NO_CREDIT = 'ai_no_credit';

    public const REASON_AI_FAILED = 'ai_failed';

    protected $fillable = [
        'incident_id',
        'conversation_id',
        'connection_id',
        'kind',
        'status',
        'source',
        'matched_keyword',
        'excluded_keyword',
        'trigger_message_id',
        'message_id',
        'reason',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'kind' => DeliveryKind::class,
        'status' => DeliveryStatus::class,
        'source' => DeliverySource::class,
        'sent_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
