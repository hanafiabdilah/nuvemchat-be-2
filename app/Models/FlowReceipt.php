<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One proof of payment read by a flow's Receipt node. See the migration.
 */
class FlowReceipt extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'flow_id',
        'flow_node_id',
        'message_id',
        'ai_hub_run_id',
        'status',
        'reason',
        'amount_cents',
        'currency',
        'payer',
        'recipient',
        'paid_on',
        'transaction_id',
        'file_hash',
        'result',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'result' => 'array',
    ];
}
