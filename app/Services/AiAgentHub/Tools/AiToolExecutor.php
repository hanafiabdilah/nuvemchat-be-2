<?php

namespace App\Services\AiAgentHub\Tools;

use App\Enums\Catalog\OrderStatus;
use App\Enums\Flow\FlowPaymentStatus;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\Catalog\OrderService;
use App\Services\Flow\FlowExecutor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Running one tool the model asked for, against this conversation.
 *
 * Every tool answers the same shape: `ok`, a structured `result`, and
 * `message_for_model` — one or two sentences the model can act on. A business
 * refusal ("only 2 left", "the cart is empty") is `ok: false` with a clear
 * sentence, never an exception: the model is expected to read it and tell the
 * customer, and that is the whole point of letting it ask.
 */
final class AiToolExecutor
{
    public function __construct(
        private OrderService $orders,
        private FlowExecutor $flows,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  already checked by AiToolArguments
     * @return array{ok: bool, result: array<string, mixed>, message_for_model: string}
     */
    public function run(
        string $tool,
        array $arguments,
        Tenant $tenant,
        Conversation $conversation,
        FlowState $flowState,
        FlowNode $node,
    ): array {
        return match ($tool) {
            'search_products' => $this->searchProducts($tenant, (string) ($arguments['query'] ?? '')),
            'get_product' => $this->getProduct($tenant, (int) $arguments['product_id']),
            'cart_add' => $this->cartAdd($tenant, $conversation, $flowState, $node, (int) $arguments['variant_id'], (int) $arguments['quantity']),
            'cart_remove' => $this->cartRemove($conversation, (int) $arguments['variant_id'], isset($arguments['quantity']) ? (int) $arguments['quantity'] : null),
            'cart_view' => $this->cartView($conversation),
            'create_payment' => $this->createPayment($conversation, $flowState, $node),
            default => self::refuse('unknown_tool', "There is no tool called \"{$tool}\"."),
        };
    }

    // ─────────────────────────────  Catalog  ─────────────────────────────

    private function searchProducts(Tenant $tenant, string $query): array
    {
        $limit = max(1, min((int) config('ai.tools.search_limit', 8), 25));
        $terms = array_values(array_filter(
            preg_split('/\s+/u', Str::lower(Str::ascii(trim($query)))) ?: [],
            fn ($term) => mb_strlen($term) >= 2,
        ));

        $variants = ProductVariant::query()
            ->where('product_variants.tenant_id', $tenant->id)
            ->where('product_variants.active', true)
            ->whereHas('product', fn (Builder $q) => $q->where('active', true))
            ->with('product')
            ->when($terms !== [], function (Builder $q) use ($terms) {
                // Every word must appear somewhere — the product, the
                // variation, the SKU or the description. "camiseta preta g"
                // should not match every black thing in the shop.
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $q->where(function (Builder $w) use ($like) {
                        $w->whereRaw('LOWER(product_variants.name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(product_variants.sku) LIKE ?', [$like])
                            ->orWhereHas('product', fn (Builder $p) => $p
                                ->whereRaw('LOWER(name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(description) LIKE ?', [$like]));
                    });
                }
            })
            ->orderBy('product_id')
            ->orderBy('position')
            ->limit($limit + 1)
            ->get();

        // Accents: the query is folded to ASCII and so, on MySQL, is the
        // collation. SQLite compares bytes, so a second pass keeps "calça"
        // findable by "calca" there too.
        if ($variants->isEmpty() && $terms !== []) {
            $variants = $this->accentFoldedSearch($tenant, $terms, $limit + 1);
        }

        $more = $variants->count() > $limit;
        $items = $variants->take($limit)->map(fn (ProductVariant $v) => $this->variantPayload($v, $tenant))->values()->all();

        if ($items === []) {
            return [
                'ok' => true,
                'result' => ['items' => []],
                'message_for_model' => $terms === []
                    ? 'The catalog is empty.'
                    : 'No product matches "'.trim($query).'". Ask the customer to describe it differently, or offer what the shop has.',
            ];
        }

        return [
            'ok' => true,
            'result' => ['items' => $items, 'more' => $more],
            'message_for_model' => count($items).' result(s)'.($more ? ' (more exist — narrow the search)' : '').': '
                .collect($items)->map(fn ($i) => "{$i['name']} (variant_id {$i['variant_id']}), {$i['price']}, ".$this->stockPhrase($i['stock']))->implode('; ').'.',
        ];
    }

    /** @param  list<string>  $terms */
    private function accentFoldedSearch(Tenant $tenant, array $terms, int $take)
    {
        return ProductVariant::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->whereHas('product', fn (Builder $q) => $q->where('active', true))
            ->with('product')
            ->orderBy('product_id')->orderBy('position')
            ->limit(500)
            ->get()
            ->filter(function (ProductVariant $v) use ($terms) {
                $haystack = Str::lower(Str::ascii(implode(' ', [
                    $v->product?->name, $v->name, $v->sku, $v->product?->description,
                ])));

                foreach ($terms as $term) {
                    if (! str_contains($haystack, $term)) {
                        return false;
                    }
                }

                return true;
            })
            ->take($take)
            ->values();
    }

    private function getProduct(Tenant $tenant, int $productId): array
    {
        $product = Product::forTenant($tenant->id)->where('active', true)->with('variants')->find($productId);

        if (! $product) {
            return self::refuse('not_found', "There is no product with product_id {$productId}. Use search_products to find it.");
        }

        $variants = $product->variants->where('active', true)
            ->map(fn (ProductVariant $v) => $this->variantPayload($v->setRelation('product', $product), $tenant))
            ->values()->all();

        return [
            'ok' => true,
            'result' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => $product->description ? Str::limit($product->description, 600) : null,
                'variants' => $variants,
            ],
            'message_for_model' => "{$product->name}: ".collect($variants)
                ->map(fn ($v) => ($v['variation'] ?? $v['name'])." (variant_id {$v['variant_id']}) {$v['price']}, ".$this->stockPhrase($v['stock']))
                ->implode('; ').'.',
        ];
    }

    /** @return array<string, mixed> */
    private function variantPayload(ProductVariant $variant, Tenant $tenant): array
    {
        return array_filter([
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->displayName(),
            'variation' => $variant->name,
            'sku' => $variant->sku,
            'price' => $variant->formattedPrice($tenant->currency()),
            // null = not counted, i.e. always available.
            'stock' => $variant->stock,
            'available' => ! $variant->tracksStock() || $variant->stock > 0,
            'description' => $variant->product?->description ? Str::limit($variant->product->description, 200) : null,
        ], fn ($value, $key) => $value !== null || $key === 'stock', ARRAY_FILTER_USE_BOTH);
    }

    private function stockPhrase(?int $stock): string
    {
        return match (true) {
            $stock === null => 'available',
            $stock <= 0 => 'out of stock',
            default => "{$stock} in stock",
        };
    }

    // ───────────────────────────────  Cart  ───────────────────────────────

    private function cartAdd(Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node, int $variantId, int $quantity): array
    {
        $variant = ProductVariant::where('tenant_id', $tenant->id)->with('product')->find($variantId);

        if (! $variant || ! $variant->isSellable()) {
            return self::refuse('not_found', "variant_id {$variantId} is not an item on sale. Use search_products to find the right one.");
        }

        $cart = $this->orders->cartFor($conversation, $flowState, $node->id);
        $outcome = $this->orders->add($cart, $variant, $quantity);

        if (! $outcome['ok']) {
            if (($outcome['error'] ?? null) === 'insufficient_stock') {
                $available = (int) ($outcome['available'] ?? 0);
                $inCart = (int) ($outcome['in_cart'] ?? 0);

                return self::refuse(
                    'insufficient_stock',
                    $available === 0
                        ? "\"{$variant->displayName()}\" is out of stock."
                        : "Only {$available} of \"{$variant->displayName()}\" are in stock".($inCart > 0 ? " and {$inCart} are already in the cart" : '').'. Nothing was added.',
                    ['available' => $available, 'in_cart' => $inCart],
                );
            }

            return self::refuse('unavailable', "\"{$variant->displayName()}\" is not available.");
        }

        return $this->cartAnswer($cart->refresh(), "Added {$quantity}x {$variant->displayName()}.");
    }

    private function cartRemove(Conversation $conversation, int $variantId, ?int $quantity): array
    {
        $cart = $this->orders->openCart($conversation);

        if (! $cart || ! $this->orders->remove($cart, $variantId, $quantity)) {
            return self::refuse('not_in_cart', "variant_id {$variantId} is not in the cart.");
        }

        return $this->cartAnswer($cart->refresh(), 'Removed.');
    }

    private function cartView(Conversation $conversation): array
    {
        $cart = $this->orders->openCart($conversation);

        if (! $cart || $cart->items()->doesntExist()) {
            return ['ok' => true, 'result' => ['items' => [], 'total' => null], 'message_for_model' => 'The cart is empty.'];
        }

        return $this->cartAnswer($cart);
    }

    private function cartAnswer(Order $cart, string $lead = ''): array
    {
        $cart->load('items');

        $items = $cart->items->map(fn ($item) => [
            'variant_id' => $item->product_variant_id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price' => Money::format($item->unit_price_cents, $cart->currency),
            'line_total' => Money::format($item->line_total_cents, $cart->currency),
        ])->all();

        $summary = $items === []
            ? 'The cart is empty.'
            : 'Cart: '.collect($items)->map(fn ($i) => "{$i['quantity']}x {$i['name']} = {$i['line_total']}")->implode('; ')
                .'. Total: '.$cart->formattedTotal().'.';

        return [
            'ok' => true,
            'result' => ['order_id' => $cart->id, 'items' => $items, 'total' => $cart->formattedTotal()],
            'message_for_model' => trim($lead.' '.$summary),
        ];
    }

    // ─────────────────────────────  Payment  ─────────────────────────────

    private function createPayment(Conversation $conversation, FlowState $flowState, FlowNode $node): array
    {
        $cart = $this->orders->openCart($conversation);

        if (! $cart) {
            // Asked twice for the same cart: the charge already exists, and a
            // second one for the same order is the one thing never to issue.
            $pending = Order::where('conversation_id', $conversation->id)
                ->where('status', OrderStatus::AwaitingPayment->value)
                ->latest('id')
                ->first();

            $payment = $pending?->payments()->first();

            if ($payment && $payment->status === FlowPaymentStatus::Pending) {
                return [
                    'ok' => true,
                    'result' => ['order_id' => $pending->id, 'total' => $pending->formattedTotal(), 'already_issued' => true],
                    'message_for_model' => "The payment for order #{$pending->id} ({$pending->formattedTotal()}) was already sent to the customer and is still open. Do not charge again.",
                ];
            }

            return self::refuse('empty_cart', 'The cart is empty. Add the items the customer wants with cart_add first.');
        }

        $changes = $this->orders->reprice($cart);
        $cart->refresh();

        if ($cart->items()->doesntExist() || $cart->total_cents <= 0) {
            return self::refuse('empty_cart', trim(implode(' ', $changes).' The cart is empty, so there is nothing to charge.'));
        }

        // Anything that changed since the cart was built is said before
        // charging, not after: the customer must agree to the new total.
        if ($changes !== []) {
            return self::refuse(
                'cart_changed',
                implode(' ', $changes).' The new total is '.$cart->formattedTotal().'. Confirm it with the customer, then call create_payment again.',
                ['total' => $cart->formattedTotal()],
            );
        }

        $payment = $this->flows->chargeCartFromTool($flowState, $node, $cart);

        if ($payment->status === FlowPaymentStatus::Failed) {
            return self::refuse(
                'payment_failed',
                'The payment could not be created ('.($payment->failure_reason ?? 'unknown reason').'). Tell the customer and offer to hand them to the team.',
            );
        }

        $minutes = $payment->expires_at ? max(1, (int) round(now()->diffInMinutes($payment->expires_at))) : null;

        return [
            'ok' => true,
            'result' => array_filter([
                'order_id' => $cart->id,
                'total' => $payment->formattedAmount(),
                'expires_in_minutes' => $minutes,
                'sent_to_customer' => true,
            ], fn ($v) => $v !== null),
            'message_for_model' => "Payment of {$payment->formattedAmount()} created for order #{$cart->id}. "
                .'The payment details were already sent to the customer in separate messages — do not repeat the code or the link. '
                .($minutes ? "Tell them it is valid for {$minutes} minutes and that you will confirm as soon as it is paid." : 'Tell them you will confirm as soon as it is paid.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{ok: false, result: array<string, mixed>, message_for_model: string}
     */
    private static function refuse(string $code, string $message, array $extra = []): array
    {
        return [
            'ok' => false,
            'result' => array_merge(['error' => $code], $extra),
            'message_for_model' => $message,
        ];
    }
}
