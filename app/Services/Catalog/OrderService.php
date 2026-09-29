<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\OrderStatus;
use App\Enums\Catalog\StockMovementReason;
use App\Enums\Flow\FlowPaymentStatus;
use App\Models\Conversation;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\Money\MarketMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cart the AI builds in a conversation, and what happens to it when the
 * money arrives.
 *
 * The one rule everything here serves: **no amount ever comes from the
 * model.** The AI names a variant and a quantity; the price is read from the
 * catalog, the total is summed here, and the charge is for this total. A model
 * that computed totals would one day compute one wrong, in front of a customer
 * who is paying it.
 *
 * Stock is only *checked* while the cart is built (so the AI can say "only 2
 * left") and only *taken* when the charge is paid — see markPaid(). Taking it
 * earlier would hold units for carts nobody pays for.
 */
final class OrderService
{
    /** Per line. Past this it is a typo or a joke, not an order over chat. */
    public const MAX_QUANTITY = 999;

    public function __construct(private StockService $stock) {}

    /** The cart being filled in this conversation, if there is one. */
    public function openCart(Conversation $conversation): ?Order
    {
        return Order::where('conversation_id', $conversation->id)
            ->where('status', OrderStatus::Open->value)
            ->latest('id')
            ->first();
    }

    /** The open cart, created on first use. */
    public function cartFor(Conversation $conversation, FlowState $flowState, int $nodeId): Order
    {
        if ($cart = $this->openCart($conversation)) {
            return $cart;
        }

        $tenant = $conversation->connection?->tenant;

        return Order::create([
            'tenant_id' => (int) $conversation->connection->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'flow_id' => $flowState->flow_id,
            'flow_node_id' => $nodeId,
            'status' => OrderStatus::Open,
            'currency' => $tenant?->currency() ?? MarketMoney::baseCurrency(),
            'total_cents' => 0,
        ]);
    }

    /**
     * Put units of a variant in the cart (added to what is already there).
     *
     * @return array{ok: bool, error?: string, available?: int|null, item?: OrderItem}
     */
    public function add(Order $order, ProductVariant $variant, int $quantity): array
    {
        if (! $variant->isSellable()) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        $quantity = max(1, min($quantity, self::MAX_QUANTITY));

        return DB::transaction(function () use ($order, $variant, $quantity) {
            $item = $order->items()->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            $wanted = ($item?->quantity ?? 0) + $quantity;

            // Checked, not reserved: the units are only taken when the charge
            // is paid. A shop that sells out between now and then gets a
            // negative number rather than a refused payment.
            if ($variant->tracksStock() && $wanted > $variant->stock) {
                return ['ok' => false, 'error' => 'insufficient_stock', 'available' => max(0, (int) $variant->stock), 'in_cart' => (int) ($item?->quantity ?? 0)];
            }

            $wanted = min($wanted, self::MAX_QUANTITY);

            $attributes = [
                'name' => $variant->displayName(),
                'sku' => $variant->sku,
                'unit_price_cents' => $variant->price_cents,
                'quantity' => $wanted,
                'line_total_cents' => $variant->price_cents * $wanted,
            ];

            if ($item) {
                $item->update($attributes);
            } else {
                $item = $order->items()->create(array_merge($attributes, ['product_variant_id' => $variant->id]));
            }

            $this->recalculate($order);

            return ['ok' => true, 'item' => $item];
        });
    }

    /**
     * Take units out of the cart. No quantity (or more than there is) removes
     * the line.
     */
    public function remove(Order $order, int $variantId, ?int $quantity = null): bool
    {
        return DB::transaction(function () use ($order, $variantId, $quantity) {
            $item = $order->items()->where('product_variant_id', $variantId)->lockForUpdate()->first();

            if (! $item) {
                return false;
            }

            if ($quantity === null || $quantity >= $item->quantity) {
                $item->delete();
            } else {
                $remaining = $item->quantity - max(1, $quantity);
                $item->update([
                    'quantity' => $remaining,
                    'line_total_cents' => $item->unit_price_cents * $remaining,
                ]);
            }

            $this->recalculate($order);

            return true;
        });
    }

    /**
     * Refresh every line against the catalog before charging.
     *
     * The cart may have been built an hour ago; the price may have changed
     * and the product may have been switched off since. The customer is
     * charged what the catalog says now, and told so by the tool result.
     *
     * @return list<string> what changed, in plain words for the model
     */
    public function reprice(Order $order): array
    {
        $changes = [];

        foreach ($order->items()->get() as $item) {
            $variant = $item->variant;

            if (! $variant || ! $variant->isSellable()) {
                $changes[] = "\"{$item->name}\" is no longer available and was removed from the cart.";
                $item->delete();

                continue;
            }

            if ($variant->tracksStock() && $item->quantity > $variant->stock) {
                $available = max(0, (int) $variant->stock);

                if ($available === 0) {
                    $changes[] = "\"{$item->name}\" sold out and was removed from the cart.";
                    $item->delete();

                    continue;
                }

                $changes[] = "Only {$available} of \"{$item->name}\" are left; the quantity was reduced to {$available}.";
                $item->quantity = $available;
            }

            if ($variant->price_cents !== $item->unit_price_cents) {
                $changes[] = "The price of \"{$item->name}\" changed to {$variant->formattedPrice($order->currency)}.";
                $item->unit_price_cents = $variant->price_cents;
            }

            $item->line_total_cents = $item->unit_price_cents * $item->quantity;
            $item->save();
        }

        $this->recalculate($order);

        return $changes;
    }

    public function recalculate(Order $order): void
    {
        $order->forceFill(['total_cents' => (int) $order->items()->sum('line_total_cents')])->save();
        $order->unsetRelation('items');
    }

    /** The charge exists; the cart is closed to edits. */
    public function markAwaitingPayment(Order $order): void
    {
        $order->forceFill(['status' => OrderStatus::AwaitingPayment])->save();
    }

    /** The charge could not be created: close the cart so the next try starts clean. */
    public function markCancelled(Order $order): void
    {
        if ($order->status->isClosed()) {
            return;
        }

        $order->forceFill(['status' => OrderStatus::Cancelled, 'closed_at' => now()])->save();
    }

    /**
     * The gateway settled a charge for this order. Called from
     * FlowPaymentService::settle(), which runs once per charge — so the stock
     * is taken once, however many webhooks arrive.
     *
     * A payment that arrives after the order expired is still a sale: the money
     * is real, so the order becomes paid and the stock is taken.
     */
    public function syncFromPayment(FlowPayment $payment): void
    {
        $order = $payment->order_id ? Order::find($payment->order_id) : null;

        if (! $order) {
            return;
        }

        $paid = $payment->status === FlowPaymentStatus::Paid || $payment->paid_at !== null;

        if ($paid) {
            $this->markPaid($order);

            return;
        }

        // A newer charge for the same order may still be open; only the
        // latest one decides the order's fate.
        $latest = $order->payments()->first();

        if ($latest && $latest->id !== $payment->id) {
            return;
        }

        if ($order->status === OrderStatus::AwaitingPayment) {
            $order->forceFill([
                'status' => $payment->status === FlowPaymentStatus::Expired ? OrderStatus::Expired : OrderStatus::Cancelled,
                'closed_at' => now(),
            ])->save();
        }
    }

    private function markPaid(Order $order): void
    {
        $taken = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === OrderStatus::Paid) {
                return false;
            }

            $locked->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
                'closed_at' => now(),
            ])->save();

            return true;
        });

        if (! $taken) {
            return;
        }

        foreach ($order->items()->with('variant')->get() as $item) {
            if ($item->variant) {
                $this->stock->adjust($item->variant, -$item->quantity, StockMovementReason::OrderPaid, $order->id);
            }
        }

        Log::info('OrderService: order paid, stock taken', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'total_cents' => $order->total_cents,
        ]);
    }
}
