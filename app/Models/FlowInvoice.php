<?php

namespace App\Models;

use App\Enums\Flow\FlowInvoiceStatus;
use App\Enums\Integration\IntegrationProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A nota fiscal a flow's invoice node asked the workspace's issuing platform to
 * emit.
 *
 * See the migration for why this row is the meeting point of the webhook, the
 * deadline job and the polling sweep.
 */
class FlowInvoice extends Model
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
        'flow_payment_id',
        'reference',
        'provider_invoice_id',
        'amount_cents',
        'currency',
        'description',
        'customer_name',
        'customer_document',
        'customer_email',
        'status',
        'number',
        'pdf_url',
        'xml_url',
        'wait_until',
        'issued_at',
        'settled_at',
        'last_checked_at',
        'failure_reason',
        'meta',
    ];

    protected $casts = [
        'provider' => IntegrationProvider::class,
        'status' => FlowInvoiceStatus::class,
        'amount_cents' => 'integer',
        'wait_until' => 'datetime',
        'issued_at' => 'datetime',
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

    public function payment()
    {
        return $this->belongsTo(FlowPayment::class, 'flow_payment_id');
    }

    public function isProcessing(): bool
    {
        return $this->status === FlowInvoiceStatus::Processing;
    }

    /** "49.90" — the form issuing platforms take. */
    public function amountDecimal(): string
    {
        return number_format($this->amount_cents / 100, 2, '.', '');
    }

    /** "R$ 49,90" — the form the customer reads, through {{invoice_amount}}. */
    public function formattedAmount(): string
    {
        $symbol = $this->currency === 'BRL' ? 'R$' : $this->currency;

        return $symbol.' '.number_format($this->amount_cents / 100, 2, ',', '.');
    }

    /**
     * The document file, behind our own signed link — see InvoiceIssuer::document()
     * for why the customer is never sent the provider's.
     *
     * Null until the invoice is issued: there is no file before that.
     */
    public function documentUrl(string $format = 'pdf'): ?string
    {
        if ($this->status !== FlowInvoiceStatus::Issued || $this->integration_id === null) {
            return null;
        }

        $number = $this->number ? '-'.Str::slug($this->number) : '';

        return URL::signedRoute('flow-invoices.document', [
            'reference' => $this->reference,
            'filename' => "nota-fiscal{$number}.{$format}",
        ]);
    }

    /**
     * "•••.456.789-••" — enough for an agent to recognise whose invoice it is,
     * not enough to copy a CPF out of the dashboard.
     */
    public function maskedDocument(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->customer_document);

        return match (strlen((string) $digits)) {
            11 => '•••.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-••',
            14 => '••.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-••',
            default => null,
        };
    }
}
