<?php

namespace App\Services\Integrations\Payments;

use App\Models\FlowPayment;

/**
 * A gateway whose charges outlive the node's deadline unless somebody closes
 * them.
 *
 * OpenPix and Mercado Pago end a Pix at the expiry we sent. Asaas does not — its
 * dynamic QR stays payable for months after the due date — and a Stripe
 * Checkout link lives up to a day. Left open, the customer who was told "the
 * deadline passed" can still pay, and that money lands as "paid late" on a flow
 * that already took the other branch. Closing the charge at the deadline is
 * what makes the deadline true.
 *
 * Best-effort by contract: FlowPaymentService calls it after the row is already
 * expired, and swallows a refusal — a charge the customer paid in the last
 * second cannot be cancelled, and the read-back that follows records it.
 */
interface CancelsCharges
{
    public function cancelCharge(FlowPayment $payment): void;
}
