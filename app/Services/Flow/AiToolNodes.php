<?php

namespace App\Services\Flow;

/**
 * The "Agente IA com ações" node's vocabulary (type `ai_tools`).
 *
 * Mirrored on the frontend in `lib/aiToolNodes.ts` — the branch names are
 * source handle ids and edge condition_values at once, so both sides have to
 * spell them identically, and the frontend decides which handles to draw from
 * the same capability rules as branches() below.
 *
 * What the node is: an AI Agent node (same data, same machinery — see
 * FlowExecutor::executeAIAgentNode) that the author lets act. Capabilities are
 * ticked, never written: the definitions the model sees are in code
 * (App\Services\AiAgentHub\Tools\AiToolCatalog), because the person building a
 * flow is a shop owner, not somebody who writes JSON Schema.
 *
 * Outputs follow the capabilities, and only the ones that produce an event
 * worth drawing a step for have any:
 *
 *   handoff         — always. Where the AI Agent node's single output goes
 *                     (next node in `always_ai`, or when no person is there).
 *   paid            — "Cobrar via Pix" ticked; the customer paid the cart.
 *   payment_failed  — the same; the Pix expired or was refused.
 *
 * An output left unwired means "the AI carries on": a fresh node with no edges
 * behaves exactly like an AI Agent node.
 */
final class AiToolNodes
{
    public const BRANCH_HANDOFF = 'handoff';

    public const BRANCH_PAID = 'paid';

    public const BRANCH_PAYMENT_FAILED = 'payment_failed';

    /** Order variables written when a cart is charged, for the steps after `paid`. */
    public const ORDER_VARIABLES = ['order_id', 'order_total', 'order_items', 'order_status'];

    /** Info-note codes, rendered by `lib/infoMessage.ts`. */
    public const INFO_TOOL_FAILED = 'ai_tool_failed';

    /**
     * Whether tools reach the hub at all. Off, an ai_tools node runs exactly
     * like an AI Agent node: nothing is added to the run.
     */
    public static function enabled(): bool
    {
        return (bool) config('ai.tools.enabled', false);
    }

    /**
     * The node's capabilities, read tolerantly — absent keys are off.
     *
     * Dependencies are enforced here, not trusted from the form: a cart with
     * no catalog has no ids to put in it, and a charge with no cart has no
     * amount. A node saved with "payment" but not "cart" charges nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array{catalog: bool, cart: bool, payment: bool}
     */
    public static function capabilities(array $data): array
    {
        $caps = (array) ($data['capabilities'] ?? []);

        $catalog = ($caps['catalog'] ?? false) === true;
        $cart = $catalog && ($caps['cart'] ?? false) === true;
        $payment = $cart
            && (bool) (($caps['payment'] ?? [])['enabled'] ?? false)
            && (int) (($caps['payment'] ?? [])['integration_id'] ?? 0) > 0;

        return [
            'catalog' => $catalog,
            'cart' => $cart,
            'payment' => $payment,
        ];
    }

    /**
     * The branches this node emits. "Cobrar via Pix" counts once it is
     * ticked, even before an account is chosen — the author is wiring the
     * steps while configuring, and a handle that vanishes because an account
     * is still unpicked would drop the edge they just drew.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function branches(array $data): array
    {
        $caps = (array) ($data['capabilities'] ?? []);
        $branches = [self::BRANCH_HANDOFF];

        if ((bool) (($caps['payment'] ?? [])['enabled'] ?? false)) {
            $branches[] = self::BRANCH_PAID;
            $branches[] = self::BRANCH_PAYMENT_FAILED;
        }

        return $branches;
    }

    /**
     * What the turn tells the agent about the tools it has, ahead of the
     * customer's words. Empty when there are none.
     *
     * Written for the agent, in English, and deliberately free of the words
     * the hub's handoff detector reacts to ("atendente", "humano"…): this text
     * travels in `message.content`, which that detector scans — the lesson of
     * the welcome preamble that handed off 53 of 53 runs.
     *
     * @param  list<string>  $tools
     */
    public static function preamble(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $lines = [
            'The shop\'s product catalog is available through your tools and it is the ONLY source of truth for what is sold, prices and stock. Prices or product lists written anywhere else (your instructions, your knowledge, earlier messages) may be outdated — do not quote them.',
            'Before answering anything about products, prices or availability, call search_products (use an empty query to list everything) or get_product, and answer from what they return.',
        ];

        if (in_array('cart_add', $tools, true)) {
            $lines[] = 'When the customer wants to buy, add the chosen items with cart_add (using the variant_id from the catalog) and confirm the items and total with cart_view.';
        }

        if (in_array('create_payment', $tools, true)) {
            $lines[] = 'When the customer confirms and asks to pay, call create_payment: it charges the cart total and sends the payment (Pix code/QR or link) to the customer by itself. Do not ask for other billing details to charge the cart, and do not repeat the code in your reply.';
        }

        return '[Shop tools — '.implode(' ', $lines)."]\n\n";
    }

    /** @param  array<string, mixed>  $data
     *  @return array<string, mixed> */
    public static function paymentConfig(array $data): array
    {
        $payment = (array) (($data['capabilities'] ?? [])['payment'] ?? []);

        return [
            'integration_id' => (int) ($payment['integration_id'] ?? 0),
            'method' => PaymentNodes::method($payment),
            'expires_in_minutes' => PaymentNodes::expiresInMinutes($payment),
            'payer_document' => (string) ($payment['payer_document'] ?? ''),
            'payer_email' => (string) ($payment['payer_email'] ?? ''),
            'send_qr_code' => true,
            'send_copy_paste' => true,
            'send_link' => PaymentNodes::method($payment) === PaymentNodes::METHOD_CHECKOUT,
        ];
    }
}
