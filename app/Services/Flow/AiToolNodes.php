<?php

namespace App\Services\Flow;

use Carbon\CarbonImmutable;

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
 *   reservation_confirmed — "Take reservations" ticked; a reservation the AI
 *                     took was confirmed (by the calendar, by the team, by the
 *                     customer taking an offered time, or by its payment).
 *                     Taken after the AI has told the customer.
 *
 * Two independent things the AI can act on: the product catalog (and its
 * cart), and the reservation calendar. The payment account charges both — the
 * cart's total, and what a reservation's service asks for — but only a cart
 * charge takes `paid` / `payment_failed`; a reservation's money ends in
 * `reservation_confirmed` (paid) or the AI telling the customer (expired).
 *
 * An output left unwired means "the AI carries on": a fresh node with no edges
 * behaves exactly like an AI Agent node.
 */
final class AiToolNodes
{
    public const BRANCH_HANDOFF = 'handoff';

    public const BRANCH_PAID = 'paid';

    public const BRANCH_PAYMENT_FAILED = 'payment_failed';

    public const BRANCH_RESERVATION_CONFIRMED = 'reservation_confirmed';

    /** Order variables written when a cart is charged, for the steps after `paid`. */
    public const ORDER_VARIABLES = ['order_id', 'order_total', 'order_items', 'order_status'];

    /** Reservation variables, for the steps after `reservation_confirmed`. */
    public const RESERVATION_VARIABLES = [
        'reservation_id', 'reservation_code', 'reservation_service', 'reservation_date',
        'reservation_time', 'reservation_party_size', 'reservation_status', 'reservation_amount',
    ];

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
     * Whether the reservation tools reach the hub. Off until the hub accepts
     * their names: the hub validates the whole catalog at once, so one name
     * it does not know would take the shop tools down with it. Off, a node
     * with "Take reservations" ticked runs as if it were not.
     */
    public static function reservationsEnabled(): bool
    {
        return self::enabled() && (bool) config('ai.tools.reservations', false);
    }

    /**
     * The node's capabilities, read tolerantly — absent keys are off.
     *
     * Dependencies are enforced here, not trusted from the form: a cart with
     * no catalog has no ids to put in it, and a charge with neither a cart nor
     * a reservation has no amount. `payment` is the cart charge
     * (create_payment); `reservation_payment` is the same account charging
     * what a reservation's service asks for.
     *
     * `reservations` is `{enabled, service_ids}` (an empty list = every
     * service); a bare `true` is read as every service.
     *
     * @param  array<string, mixed>  $data
     * @return array{catalog: bool, cart: bool, payment: bool, reservations: bool, reservation_service_ids: list<int>, reservation_payment: bool}
     */
    public static function capabilities(array $data): array
    {
        $caps = (array) ($data['capabilities'] ?? []);

        $catalog = ($caps['catalog'] ?? false) === true;
        $cart = $catalog && ($caps['cart'] ?? false) === true;
        $account = (bool) (($caps['payment'] ?? [])['enabled'] ?? false)
            && (int) (($caps['payment'] ?? [])['integration_id'] ?? 0) > 0;

        $bookings = $caps['reservations'] ?? false;
        $reservations = $bookings === true || (is_array($bookings) && ($bookings['enabled'] ?? false) === true);
        $serviceIds = $reservations && is_array($bookings)
            ? array_values(array_unique(array_filter(array_map('intval', (array) ($bookings['service_ids'] ?? [])), fn (int $id) => $id > 0)))
            : [];

        return [
            'catalog' => $catalog,
            'cart' => $cart,
            'payment' => $cart && $account,
            'reservations' => $reservations,
            'reservation_service_ids' => $serviceIds,
            'reservation_payment' => $reservations && $account,
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

        if (self::capabilities($data)['reservations']) {
            $branches[] = self::BRANCH_RESERVATION_CONFIRMED;
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
     * The reservation lines carry today's date in the shop's own timezone:
     * the model has no clock, so without it "tomorrow" or "Saturday" has no
     * date to land on.
     *
     * @param  list<string>  $tools
     */
    public static function preamble(array $tools, ?string $timezone = null): string
    {
        if ($tools === []) {
            return '';
        }

        $lines = [];

        if (in_array('search_products', $tools, true)) {
            $lines[] = 'The shop\'s product catalog is available through your tools and it is the ONLY source of truth for what is sold, prices and stock. Prices or product lists written anywhere else (your instructions, your knowledge, earlier messages) may be outdated — do not quote them.';
            $lines[] = 'Before answering anything about products, prices or availability, call search_products (use an empty query to list everything) or get_product, and answer from what they return.';
        }

        if (in_array('cart_add', $tools, true)) {
            $lines[] = 'When the customer wants to buy, add the chosen items with cart_add (using the variant_id from the catalog) and confirm the items and total with cart_view.';
        }

        if (in_array('create_payment', $tools, true)) {
            $lines[] = 'When the customer confirms and asks to pay, call create_payment: it charges the cart total and sends the payment (Pix code/QR or link) to the customer by itself. Do not ask for other billing details to charge the cart, and do not repeat the code in your reply.';
        }

        if (in_array('request_reservation', $tools, true)) {
            $lines[] = 'Reservations: the shop\'s reservation calendar is available through your tools and it is the ONLY source of truth for what can be booked and which times are free. Never promise a time you have not seen free in check_availability. Dates are YYYY-MM-DD and times HH:MM, in the shop\'s local time; work out "tomorrow" or "Saturday" from today\'s date.';
            $lines[] = 'Today is '.CarbonImmutable::now($timezone ?: config('app.timezone'))->format('l Y-m-d, H:i').' in the shop\'s local time.';
            $lines[] = 'Collect what is needed — which service (from list_reservation_services), the day, the time, how many people, the name — then call request_reservation. It tells you whether the reservation is confirmed (give the code), held until a payment is made (the payment details are sent by itself — do not repeat them), or waiting for the shop to confirm (tell the customer you will let them know here; do not promise the time).';
            $lines[] = 'When something gives you pause — a special request, anything you cannot settle — set needs_team_check with the reason. For an existing reservation, use my_reservations first; then accept_offered_time, reschedule_reservation or cancel_reservation. If a tool says the customer cannot change it in the chat, offer to pass them to the shop.';
        }

        if ($lines === []) {
            return '';
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
