<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\StockMovementReason;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only way a variant's stock changes.
 *
 * `product_variants.stock` is a cache of the movements, written in the same
 * transaction as the row that explains it and under a row lock — two paid
 * orders settling at once must each take their units, not both read "3" and
 * both write "2".
 *
 * A null stock means "not counted": adjusting it does nothing, because there
 * is no number to move and inventing one would start enforcing a limit the
 * shop never set.
 */
final class StockService
{
    /**
     * Set the stock to a number somebody typed (or null to stop counting).
     *
     * Recorded as the difference, so the movement history adds up to the
     * number on screen.
     */
    public function set(
        ProductVariant $variant,
        ?int $stock,
        StockMovementReason $reason,
        ?User $user = null,
        ?string $note = null,
    ): void {
        DB::transaction(function () use ($variant, $stock, $reason, $user, $note) {
            $locked = ProductVariant::withTrashed()->whereKey($variant->id)->lockForUpdate()->first();

            if (! $locked || $locked->stock === $stock) {
                return;
            }

            $before = $locked->stock;
            $locked->forceFill(['stock' => $stock])->save();

            // Switching counting off leaves no number to explain; switching it
            // on is the whole starting amount arriving at once.
            if ($stock === null) {
                return;
            }

            StockMovement::create([
                'tenant_id' => $locked->tenant_id,
                'product_variant_id' => $locked->id,
                'delta' => $stock - ($before ?? 0),
                'stock_after' => $stock,
                'reason' => $reason,
                'user_id' => $user?->id,
                'note' => $note,
            ]);
        });

        $variant->refresh();
    }

    /**
     * Move the stock by a delta — a sale takes units away.
     *
     * Allowed to go below zero, on purpose: this runs when the money has
     * already arrived, and refusing to record a sale that happened does not
     * un-happen it. The negative number is how the shop finds out.
     */
    public function adjust(
        ProductVariant $variant,
        int $delta,
        StockMovementReason $reason,
        ?int $orderId = null,
        ?User $user = null,
    ): void {
        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($variant, $delta, $reason, $orderId, $user) {
            $locked = ProductVariant::withTrashed()->whereKey($variant->id)->lockForUpdate()->first();

            if (! $locked || $locked->stock === null) {
                return;
            }

            $after = $locked->stock + $delta;
            $locked->forceFill(['stock' => $after])->save();

            StockMovement::create([
                'tenant_id' => $locked->tenant_id,
                'product_variant_id' => $locked->id,
                'delta' => $delta,
                'stock_after' => $after,
                'reason' => $reason,
                'order_id' => $orderId,
                'user_id' => $user?->id,
            ]);
        });

        $variant->refresh();
    }
}
