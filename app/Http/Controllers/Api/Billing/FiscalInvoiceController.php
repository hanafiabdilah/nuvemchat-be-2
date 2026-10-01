<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\Fiscal\PlugnotasException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The tenant's side of the notas fiscais Pingly issues (Brazil).
 *
 * The PDF and XML live behind Pingly's Plugnotas key, so they are streamed
 * through here rather than linked — handing a customer a Plugnotas URL would
 * hand them a link that only works with our credentials.
 */
class FiscalInvoiceController extends Controller
{
    public function document(Request $request, Invoice $invoice, string $kind, FiscalInvoiceService $notas)
    {
        abort_unless($invoice->tenant_id === $request->user()->tenant_id, 404);

        $row = $invoice->fiscalInvoice;

        abort_if($row === null || $row->provider_id === null
            || ! in_array($row->status->value, ['issued', 'cancelling', 'cancelled'], true), 404);

        try {
            $response = $notas->document($row, $kind);
        } catch (PlugnotasException $e) {
            Log::warning('Nota fiscal download failed', [
                'fiscal_invoice_id' => $row->id,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível baixar a nota fiscal agora. Tente novamente em alguns minutos.',
                'code' => 'fiscal_document_unavailable',
            ], 503);
        }

        $name = 'nota-fiscal-'.($row->number ?: $invoice->id).'.'.$kind;

        return response($response->body(), 200, [
            'Content-Type' => $kind === 'xml' ? 'application/xml' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Fill the billing address from a CEP (ViaCEP), so nobody types an IBGE
     * city code. Cached: an address does not move, and the form asks again on
     * every keystroke past eight digits.
     */
    public function cep(string $cep)
    {
        $digits = preg_replace('/\D/', '', $cep);

        if (strlen($digits) !== 8) {
            return response()->json(['message' => 'CEP inválido.', 'code' => 'invalid_cep'], 422);
        }

        $data = Cache::remember("cep:{$digits}", now()->addDays(30), function () use ($digits) {
            try {
                $response = Http::timeout(6)->connectTimeout(4)->acceptJson()
                    ->get("https://viacep.com.br/ws/{$digits}/json/");
            } catch (\Throwable) {
                return null;
            }

            $json = $response->successful() ? $response->json() : null;

            if (! is_array($json) || ($json['erro'] ?? false)) {
                return false;
            }

            return [
                'cep' => $digits,
                'logradouro' => (string) ($json['logradouro'] ?? ''),
                'bairro' => (string) ($json['bairro'] ?? ''),
                'cidade' => (string) ($json['localidade'] ?? ''),
                'estado' => (string) ($json['uf'] ?? ''),
                'codigo_cidade' => (string) ($json['ibge'] ?? ''),
            ];
        });

        if ($data === null) {
            Cache::forget("cep:{$digits}");

            return response()->json(['message' => 'Não foi possível consultar o CEP agora.', 'code' => 'cep_unavailable'], 503);
        }

        if ($data === false) {
            return response()->json(['message' => 'CEP não encontrado.', 'code' => 'cep_not_found'], 404);
        }

        return response()->json(['data' => $data]);
    }
}
