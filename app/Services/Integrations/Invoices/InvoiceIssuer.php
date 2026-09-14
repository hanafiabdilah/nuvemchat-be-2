<?php

namespace App\Services\Integrations\Invoices;

use App\Models\FlowInvoice;
use App\Services\Integrations\IntegrationDriver;
use Illuminate\Http\Request;

/**
 * A nota fiscal platform as the flow engine needs it: ask for a document, say
 * where it stands, hand over the file, and turn a webhook into "which of our
 * invoices is this".
 *
 * The same rule as PaymentGateway, for a stronger reason: a webhook body is an
 * unauthenticated claim, and "authorized" is what makes the bot send the
 * customer a fiscal document. So the body only names the invoice, and its state
 * is read back from the provider with the workspace's own key.
 */
interface InvoiceIssuer extends IntegrationDriver
{
    /**
     * Ask the provider to issue. Returns as soon as it accepted the request —
     * usually `processing`, since authorization happens after.
     */
    public function issue(InvoiceRequest $invoice): InvoiceResult;

    public function fetch(FlowInvoice $invoice): InvoiceResult;

    /**
     * The document's bytes (`pdf` or `xml`), or null when the provider has none
     * yet.
     *
     * The customer is never sent the provider's own link. Some platforms put
     * the file behind the API key, and a channel (Meta, Telegram) fetching an
     * attachment carries no key; and OutboundMedia reads the file type off the
     * URL's last segment, which a provider's `/invoices/123/pdf` does not end
     * in. So the bot sends our signed `…/nota-fiscal.pdf`, which calls this.
     */
    public function document(FlowInvoice $invoice, string $format): ?string;

    /**
     * Our references (FlowInvoice::reference) — or the provider's own invoice
     * ids — this delivery is about. Empty for anything to acknowledge and
     * ignore.
     *
     * @return list<string>
     */
    public function webhookReferences(Request $request): array;
}
