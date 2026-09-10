<?php

namespace App\Http\Controllers;

use App\Models\FlowPayment;
use App\Support\PixQrCode;
use Illuminate\Http\Response;

/**
 * The QR image of a Pix a flow sent, to whoever holds the signed link.
 *
 * Public for the same reason gallery files are: the reader that matters is the
 * channel (Meta, Telegram) fetching the bytes to deliver them, with no session.
 * The signature is the credential. Drawn on every request from the stored Pix
 * code — a pure function of a string, cheap, and never a file to purge.
 */
class FlowPaymentQrController extends Controller
{
    public function show(string $reference): Response
    {
        $payment = FlowPayment::where('reference', $reference)->first();

        abort_if($payment === null || ! $payment->pix_code || ! PixQrCode::available(), 404);

        return response(PixQrCode::png($payment->pix_code), 200, [
            'Content-Type' => 'image/png',
            // The code behind a reference never changes, so neither does the
            // picture: fetch once, keep forever.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
