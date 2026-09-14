<?php

namespace App\Http\Controllers;

use App\Enums\Flow\FlowInvoiceStatus;
use App\Models\FlowInvoice;
use App\Services\Integrations\IntegrationDrivers;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The nota fiscal a flow sent, to whoever holds the signed link — in practice
 * the channel (Meta, Telegram) fetching the attachment, then the customer.
 *
 * Fetched from the issuing platform on each request rather than stored: the
 * platform is the fiscal record, a copy of ours would be one more place a
 * cancelled invoice kept looking valid, and the file is only ever read a
 * handful of times.
 */
class FlowInvoiceDocumentController extends Controller
{
    public function show(string $reference, string $filename): Response
    {
        $invoice = FlowInvoice::with('integration')->where('reference', $reference)->first();
        $format = str_ends_with(strtolower($filename), '.xml') ? 'xml' : 'pdf';

        abort_if(
            $invoice === null
            || $invoice->integration === null
            || ! in_array($invoice->status, [FlowInvoiceStatus::Issued, FlowInvoiceStatus::Cancelled], true),
            404,
        );

        try {
            $bytes = IntegrationDrivers::invoice($invoice->integration)->document($invoice, $format);
        } catch (\Throwable $e) {
            Log::warning('FlowInvoiceDocumentController: the platform did not hand over the document', [
                'flow_invoice_id' => $invoice->id,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            abort(502);
        }

        abort_if($bytes === null || $bytes === '', 404);

        return response($bytes, 200, [
            'Content-Type' => $format === 'xml' ? 'application/xml' : 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            // Short: a cancellation afterwards should not keep serving a
            // document that reads as valid from someone's cache for a year.
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
