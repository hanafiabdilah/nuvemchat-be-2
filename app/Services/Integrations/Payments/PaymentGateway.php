<?php

namespace App\Services\Integrations\Payments;

use App\Models\FlowPayment;
use App\Services\Integrations\IntegrationDriver;
use Illuminate\Http\Request;

/**
 * A payment provider as the flow engine needs it: issue a charge, say where it
 * stands, and turn a webhook into "which of our charges is this about".
 *
 * Deliberately no "apply the webhook" method. A webhook body is an
 * unauthenticated claim — anyone who learned the URL could post "COMPLETED" —
 * so it is only ever used to learn *which* charge to look at, and the status is
 * then read back from the provider with the workspace's own key. That makes the
 * route token the only secret that matters, and makes a forged webhook cost us
 * one GET and nothing else.
 */
interface PaymentGateway extends IntegrationDriver
{
    public function createCharge(ChargeRequest $charge): ChargeResult;

    public function fetchStatus(FlowPayment $payment): ChargeStatus;

    /**
     * Our references (FlowPayment::reference) this delivery is about. Empty for
     * anything to acknowledge and ignore — test pings, events we do not track.
     *
     * @return list<string>
     */
    public function webhookReferences(Request $request): array;
}
