<?php

namespace App\Http\Controllers\Api\Numbers;

use App\Exceptions\ApiwayNumbersException;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Numbers\VirtualNumberResource;
use App\Models\AuditLog;
use App\Models\VirtualNumber;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\VirtualNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Renting virtual numbers, from the tenant's side.
 *
 * Every failure that can reach a person here is answered with something they
 * can act on: too little balance says how much is missing, a full upstream
 * account says "out of stock" rather than naming a cap that belongs to the
 * platform, and an unreachable portal is a 502 rather than a validation error
 * on a form the customer filled in correctly.
 */
class VirtualNumberController extends Controller
{
    public function __construct(
        private readonly VirtualNumberService $numbers,
    ) {}

    /** Apps, DDDs and the monthly price the tenant would pay. */
    public function catalog(Request $request)
    {
        if (! ApiwayNumbersConfig::isConfigured()) {
            return response()->json([
                'message' => 'A venda de números não está configurada na plataforma.',
                'code' => ApiwayNumbersException::UNCONFIGURED,
            ], 503);
        }

        try {
            return response()->json(['data' => $this->numbers->tenantCatalog()]);
        } catch (ApiwayNumbersException $e) {
            return $this->upstreamError($e);
        }
    }

    /** This workspace's numbers, newest first. */
    public function index(Request $request)
    {
        $numbers = $request->user()->tenant->virtualNumbers()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => VirtualNumberResource::collection($numbers)]);
    }

    /**
     * Rent a number for one month, charged to the prepaid balance.
     *
     * The response is the number itself: by the time it returns, the money has
     * moved and API Way has either activated it or refused.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ddd' => ['required', 'string', 'size:2'],
            'app' => ['required', 'string', 'max:40'],
        ]);

        $tenant = $request->user()->tenant;

        try {
            $number = $this->numbers->purchase($tenant, $validated['ddd'], $validated['app']);
        } catch (InsufficientCreditException $e) {
            // The amounts, not a bare refusal: the screen that made this call
            // has to tell the customer how much to add, and should not have to
            // subtract two figures it was never given.
            return response()->json([
                'message' => 'Seu saldo não cobre este número. Recarregue para continuar.',
                'code' => 'insufficient_credit',
                'balance_cents' => $e->balanceCents,
                'required_cents' => $e->requiredCents,
                'shortfall_cents' => $e->shortfallCents(),
            ], 422);
        } catch (ApiwayNumbersException $e) {
            return $this->upstreamError($e);
        }

        AuditLog::record('numbers.purchase', "Rented virtual number #{$number->id} ({$number->app}/{$number->ddd})");

        return response()->json(['data' => new VirtualNumberResource($number)], 201);
    }

    /**
     * One number with its messages. `?refresh=1` polls API Way first — the
     * button a person watching for a code will always press, and the fallback
     * for a webhook that has not been registered or did not arrive.
     */
    public function show(Request $request, int $id)
    {
        $number = $this->find($request, $id);

        if ($request->boolean('refresh') && $number->status->isLive()) {
            try {
                $this->numbers->pullMessages($number);
            } catch (\Throwable $e) {
                // A poll that fails must not empty the screen: what is already
                // stored is still worth showing.
                Log::warning('Virtual number message poll failed', [
                    'virtual_number_id' => $number->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => new VirtualNumberResource($number->fresh()->load('messages')),
        ]);
    }

    /**
     * End the rental.
     *
     * Stops the next charge and nothing else — the current month is already
     * paid, upstream as well as here, so there is nothing to give back. The UI
     * says so before the click; this only carries it out.
     */
    public function cancel(Request $request, int $id)
    {
        $number = $this->find($request, $id);

        try {
            $number = $this->numbers->cancel($number, 'requested');
        } catch (ApiwayNumbersException $e) {
            return $this->upstreamError($e);
        }

        AuditLog::record('numbers.cancel', "Cancelled virtual number #{$number->id}");

        return response()->json(['data' => new VirtualNumberResource($number)]);
    }

    private function find(Request $request, int $id): VirtualNumber
    {
        return $request->user()->tenant->virtualNumbers()->findOrFail($id);
    }

    private function upstreamError(ApiwayNumbersException $e)
    {
        // The account being full is not the tenant's fault and not a bad
        // request — it is stock. 409 keeps it out of the form's field errors
        // while still telling the page to say something specific.
        $status = match ($e->getErrorCode()) {
            ApiwayNumbersException::CAP_REACHED => 409,
            ApiwayNumbersException::INVALID_REQUEST => 422,
            ApiwayNumbersException::NOT_FOUND => 404,
            ApiwayNumbersException::UNCONFIGURED, ApiwayNumbersException::SALES_DISABLED => 503,
            default => 502,
        };

        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->getErrorCode(),
        ], $status);
    }
}
