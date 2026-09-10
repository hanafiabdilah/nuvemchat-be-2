<?php

namespace App\Models;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Integration\IntegrationProvider;
use App\Support\PixQrCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * A charge a flow's payment node issued in the workspace's own gateway.
 *
 * See the migration for why this row is the meeting point of the webhook, the
 * expiry job and the polling sweep.
 */
class FlowPayment extends Model
{
    protected $fillable = [
        'tenant_id',
        'integration_id',
        'provider',
        'conversation_id',
        'contact_id',
        'flow_id',
        'flow_state_id',
        'flow_node_id',
        'reference',
        'provider_payment_id',
        'method',
        'amount_cents',
        'currency',
        'description',
        'status',
        'pix_code',
        'payment_url',
        'expires_at',
        'paid_at',
        'settled_at',
        'last_checked_at',
        'failure_reason',
        'meta',
    ];

    protected $casts = [
        'provider' => IntegrationProvider::class,
        'status' => FlowPaymentStatus::class,
        'amount_cents' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'settled_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowState()
    {
        return $this->belongsTo(FlowState::class);
    }

    public function node()
    {
        return $this->belongsTo(FlowNode::class, 'flow_node_id');
    }

    public function isPending(): bool
    {
        return $this->status === FlowPaymentStatus::Pending;
    }

    /** "49.90" — the form gateways and pixels take. */
    public function amountDecimal(): string
    {
        return number_format($this->amount_cents / 100, 2, '.', '');
    }

    /**
     * "R$ 49,90" — the form the customer reads.
     *
     * Formatted here, not in the browser, because this one goes into a message
     * sent to the customer through {{payment_amount}}, and that message has no
     * browser.
     */
    public function formattedAmount(): string
    {
        $symbol = $this->currency === 'BRL' ? 'R$' : $this->currency;

        return $symbol.' '.number_format($this->amount_cents / 100, 2, ',', '.');
    }

    /**
     * The QR image for this charge, drawn on request from its Pix code.
     *
     * A route rather than a stored file: the image is a pure function of a
     * string we already keep, so storing it would buy a purge job and nothing
     * else. Signed because the channels fetching it (Meta, Telegram) carry no
     * session; the path ends in `.png` because OutboundMedia reads the MIME
     * type off the last segment.
     */
    public function qrImageUrl(): ?string
    {
        // Null rather than a link that will 500 when the channel fetches it:
        // a failed image send is a bubble the customer never sees, while no
        // image at all is simply one fewer way to pay.
        if (! $this->pix_code || ! PixQrCode::available()) {
            return null;
        }

        return URL::signedRoute('flow-payments.qr', ['reference' => $this->reference]);
    }
}
