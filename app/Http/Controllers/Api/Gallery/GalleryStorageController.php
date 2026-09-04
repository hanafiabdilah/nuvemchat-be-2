<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Exceptions\Billing\InsufficientCreditException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GalleryStorageRental;
use App\Services\Credits\CreditService;
use App\Services\Gallery\GalleryPricing;
use App\Services\Gallery\GalleryRentalService;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Http\Request;

/**
 * How much space the workspace has, and buying more of it.
 *
 * The balance is reported alongside the price on every read, so the dialog can
 * state what the change costs and whether the wallet covers it *before* the
 * button — the rule the API Way purchase modal ended up at after the checkout
 * that told people afterwards.
 */
class GalleryStorageController extends Controller
{
    public function __construct(
        private readonly GalleryStorage $storage,
        private readonly GalleryRentalService $rentals,
        private readonly CreditService $credits,
    ) {}

    /** Usage, allowance, rental and price in one read. */
    public function show(Request $request)
    {
        return response()->json(['data' => $this->state($request)]);
    }

    /**
     * What moving to `gb` would cost, without moving to it.
     *
     * A separate endpoint rather than a field on the modal's own arithmetic:
     * whether a change is charged today, prorated, or scheduled for the
     * renewal is a rule with three branches, and a copy of it in TypeScript is
     * a second rule that will disagree with this one.
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'gb' => ['required', 'integer', 'min:0', 'max:' . GalleryPricing::maxRentGb()],
        ]);

        $tenant = $request->user()->tenant;
        $quote = $this->rentals->quote($tenant, (int) $validated['gb']);
        $balance = $this->credits->balanceCents($tenant);

        return response()->json([
            'data' => $quote + [
                'balance_cents' => $balance,
                'shortfall_cents' => max(0, $quote['charge_now_cents'] - $balance),
            ],
        ]);
    }

    /**
     * Set the rented amount.
     *
     * One endpoint for renting, growing, shrinking and cancelling, because from
     * the customer's side they are one action with a number in it. Which of the
     * four it turns out to be — and whether that costs anything today — is
     * decided by GalleryRentalService, in one place.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'gb' => ['required', 'integer', 'min:0', 'max:' . GalleryPricing::maxRentGb()],
        ]);

        $gb = (int) $validated['gb'];
        $tenant = $request->user()->tenant;
        $min = GalleryPricing::minRentGb();

        // Zero is always allowed — it is how somebody stops renting. Anything
        // between zero and the floor is not: it would sell a month of storage
        // for less than it costs to bill for it.
        if ($gb > 0 && $gb < $min) {
            return response()->json([
                'message' => "O aluguel mínimo é de {$min} GB.",
                'code' => 'below_minimum',
                'min_rent_gb' => $min,
            ], 422);
        }

        $before = $this->storage->rentedGb($tenant);

        try {
            $this->rentals->setAmount($tenant, $gb);
        } catch (InsufficientCreditException $e) {
            return response()->json([
                'message' => 'Seu saldo não cobre este armazenamento. Recarregue para continuar.',
                'code' => 'insufficient_credit',
                'balance_cents' => $e->balanceCents,
                'required_cents' => $e->requiredCents,
                'shortfall_cents' => $e->shortfallCents(),
            ], 422);
        }

        AuditLog::record(
            'gallery.storage.update',
            "Gallery storage rental for tenant #{$tenant->id}: {$before} GB → {$gb} GB",
        );

        return response()->json(['data' => $this->state($request)]);
    }

    /**
     * Stop renting at the end of the paid month.
     *
     * Not immediate, and not a deletion: the month is already paid for, and
     * when the allowance does go the files stay exactly where they are. The
     * library simply stops accepting new ones until there is room again.
     */
    public function destroy(Request $request)
    {
        $tenant = $request->user()->tenant;

        $this->rentals->cancel($tenant);

        AuditLog::record('gallery.storage.cancel', "Gallery storage rental scheduled to end for tenant #{$tenant->id}");

        return response()->json(['data' => $this->state($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        $tenant = $request->user()->tenant;
        $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();

        return [
            'storage' => $this->storage->summary($tenant),
            'pricing' => GalleryPricing::settings(),
            'balance_cents' => $this->credits->balanceCents($tenant),
            'rental' => $rental === null ? null : [
                'gb' => $rental->gb,
                // What it becomes at the renewal, when a reduction is queued.
                // Null unless one is: a field that always echoes the current
                // size gives the UI nothing to distinguish.
                'pending_gb' => $rental->pending_gb,
                'status' => $rental->status->value,
                'price_per_gb_cents' => $rental->price_per_gb_cents,
                'monthly_cents' => $rental->monthlyCents(),
                'renews_at' => $rental->renews_at?->toISOString(),
                'started_at' => $rental->started_at?->toISOString(),
                'cancelled_at' => $rental->cancelled_at?->toISOString(),
                'cancel_reason' => $rental->meta['cancel_reason'] ?? null,
            ],
        ];
    }
}
