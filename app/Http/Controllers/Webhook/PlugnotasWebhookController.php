<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\FiscalInvoice;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\Fiscal\PlugnotasConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Plugnotas telling us a platform nota fiscal moved.
 *
 * Authenticated by the token we registered as a custom header (Plugnotas has
 * no signature). Even so the body is only a pointer: the nota is read back
 * from Plugnotas with our key before anything changes, so a forged delivery
 * can at most make us ask about one of our own notas again.
 *
 * Answers 200 to anything it does not recognise — a non-2xx makes Plugnotas
 * retry for six hours a delivery there is nothing to do with, and the sweep
 * (`fiscal-invoices:sync`) is the net for the ones that matter.
 */
class PlugnotasWebhookController extends Controller
{
    public function handle(Request $request, FiscalInvoiceService $notas)
    {
        $expected = PlugnotasConfig::webhookToken();
        $given = (string) $request->header(PlugnotasConfig::WEBHOOK_HEADER);

        if ($expected === null || $given === '' || ! hash_equals($expected, $given)) {
            Log::warning('Plugnotas webhook refused: bad token');

            return response()->json(['status' => 'unauthorized'], 401);
        }

        $reference = (string) ($request->input('idIntegracao') ?? '');
        $id = (string) ($request->input('id') ?? '');

        $row = FiscalInvoice::query()
            ->when($reference !== '', fn ($q) => $q->where('reference', $reference))
            ->when($reference === '' && $id !== '', fn ($q) => $q->where('provider_id', $id))
            ->when($reference === '' && $id === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();

        Log::debug('Plugnotas webhook', [
            'reference' => $reference ?: null,
            'matched' => $row?->id,
        ]);

        if ($row !== null) {
            $notas->refresh($row);
        }

        return response()->json(['status' => 'ok']);
    }
}
