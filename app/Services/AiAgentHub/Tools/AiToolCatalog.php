<?php

namespace App\Services\AiAgentHub\Tools;

use App\Enums\Billing\Feature;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use App\Services\Flow\AiToolNodes;

/**
 * The tools the model is shown, written by us and never by the flow's author.
 *
 * The author ticks capabilities; this turns each one into definitions the hub
 * hands the model — a name, a sentence about when to use it, and a JSON Schema
 * for its arguments. Three rules shape every schema here, and they are the
 * reason the definitions live in code:
 *
 *  - **no argument is money.** `cart_add` takes a variant and a quantity;
 *    `create_payment` takes nothing at all. The amount is always the cart
 *    total the server summed from catalog prices.
 *  - **no argument is an address, a header or a credential.** Nothing here
 *    reaches a URL the model chose.
 *  - **only tools the hub accepts.** The hub keeps a per-agent catalog
 *    limited to search_products, get_product, cart_add, cart_remove,
 *    cart_view and create_payment (PINGLY-TOOLS-20260928.md); anything else is
 *    refused when the catalog is registered — and the refusal takes the whole
 *    catalog with it. The seven reservation tools (list_reservation_services,
 *    check_availability, request_reservation, my_reservations,
 *    accept_offered_time, reschedule_reservation, cancel_reservation) are
 *    therefore behind their own switch (AI_RESERVATION_TOOLS_ENABLED,
 *    AiToolNodes::reservationsEnabled), to be turned on once the hub has
 *    added their names. Off, they are not in all() and no node offers them.
 *
 * Descriptions are in English on purpose: the model answers in the
 * customer's language either way, and one set of sentences serves every
 * market this runs in.
 */
final class AiToolCatalog
{
    /**
     * Definitions for the capabilities this node has ticked. Empty when it
     * has none — and an empty list adds nothing to the run.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public static function definitions(FlowNode $node, int $tenantId): array
    {
        // Each half of the node is a plan feature: the catalog's tools need
        // `catalog`, the reservation tools `reservations`. A plan with neither
        // runs an ai_tools node as a plain AI agent. Same master switch as
        // every other feature gate, so an environment without billing
        // enforcement is not locked out of it.
        $features = ['catalog' => true, 'reservations' => true];

        if (config('services.billing.enforce')) {
            $tenant = Tenant::find($tenantId);
            $gate = app(SubscriptionGate::class);

            $features = [
                'catalog' => $tenant !== null && $gate->feature($tenant, Feature::Catalog->value),
                'reservations' => $tenant !== null && $gate->feature($tenant, Feature::Reservations->value),
            ];
        }

        return self::build($node, $features);
    }

    /**
     * @param  array{catalog: bool, reservations: bool}  $features
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    private static function build(FlowNode $node, array $features): array
    {
        $caps = AiToolNodes::capabilities($node->data ?? []);
        $tools = [];

        $catalog = $caps['catalog'] && $features['catalog'];
        $cart = $caps['cart'] && $features['catalog'];

        if ($catalog) {
            $tools[] = [
                'name' => 'search_products',
                'description' => 'Search the shop\'s product catalog — the only source of truth for products, prices and stock; any price in your instructions or knowledge may be outdated. Call it before answering anything about what is sold, prices or availability, never answer those from memory. Returns matching items, each with a variant_id, its price and stock. An empty query lists all products.',
                'parameters' => self::object([
                    'query' => ['type' => 'string', 'description' => 'Words the customer used: product name, color, size, code.', 'maxLength' => 100],
                ]),
            ];
            $tools[] = [
                'name' => 'get_product',
                'description' => 'Every variation (size, color…) of one product, with price and stock for each. Use when the customer is choosing between options of a product found with search_products.',
                'parameters' => self::object([
                    'product_id' => ['type' => 'integer', 'description' => 'product_id from search_products.'],
                ], ['product_id']),
            ];
        }

        if ($cart) {
            $tools[] = [
                'name' => 'cart_add',
                'description' => 'Add units of an item to the customer\'s cart (added to what is already there). Only after the customer said they want it. Stock is checked; the price comes from the catalog.',
                'parameters' => self::object([
                    'variant_id' => ['type' => 'integer', 'description' => 'variant_id from search_products or get_product.'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999],
                ], ['variant_id', 'quantity']),
            ];
            $tools[] = [
                'name' => 'cart_remove',
                'description' => 'Take an item out of the cart. Without quantity, the whole line is removed.',
                'parameters' => self::object([
                    'variant_id' => ['type' => 'integer'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999],
                ], ['variant_id']),
            ];
            $tools[] = [
                'name' => 'cart_view',
                'description' => 'What is in the cart now, with the total. Use it to confirm the order with the customer before charging.',
                'parameters' => self::object([]),
            ];
        }

        if ($cart && $caps['payment']) {
            $tools[] = [
                'name' => 'create_payment',
                'description' => 'Charge the current cart and send the payment to the customer. Call it when the customer confirmed the items and the total and asks to pay (e.g. "send me the Pix"). No billing details are needed: the amount is the cart total. The payment details (Pix QR code and copy-and-paste code, or a payment link) are sent to the customer automatically — do not repeat them in your reply.',
                'parameters' => self::object([]),
            ];
        }

        if ($caps['reservations'] && $features['reservations'] && AiToolNodes::reservationsEnabled()) {
            array_push($tools, ...self::reservationTools());
        }

        return $tools;
    }

    /**
     * The reservation tools. No argument is an amount: what a booking pays is
     * read from its service. No tool says a time is free unless the calendar
     * does — see App\Services\Reservations\ReservationAvailability.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    private static function reservationTools(): array
    {
        $date = ['type' => 'string', 'description' => 'Local date, YYYY-MM-DD.', 'maxLength' => 10];
        $time = ['type' => 'string', 'description' => 'Local start time, HH:MM (24-hour).', 'maxLength' => 5];
        $unsure = [
            'needs_team_check' => ['type' => 'boolean', 'description' => 'true when something about the request gives you pause and the shop should check it before it is confirmed.'],
            'reason' => ['type' => 'string', 'description' => 'Why the shop should check it (with needs_team_check).', 'maxLength' => 255],
        ];

        return [
            [
                'name' => 'list_reservation_services',
                'description' => 'What can be reserved: each service with its service_id, length, opening hours, booking rules (notice, how far ahead, most people), price, any payment asked before confirming, and whether it is confirmed right away or by the shop. Call it before talking about reservations.',
                'parameters' => self::object([]),
            ],
            [
                'name' => 'check_availability',
                'description' => 'The free start times of one service on one day, for a party of a given size — read from the shop\'s calendar. When nothing is free it says why and lists the next days that have free times. Use it before offering or booking any time.',
                'parameters' => self::object([
                    'service_id' => ['type' => 'integer', 'description' => 'service_id from list_reservation_services.'],
                    'date' => $date,
                    'party_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'description' => 'How many people. Ask before checking when the service counts people.'],
                ], ['service_id', 'date']),
            ],
            [
                'name' => 'request_reservation',
                'description' => 'Book a time for the customer of this conversation, once they told you the day, the time, how many people and which service. Returns whether it is confirmed (give the code), held until a payment is made (the payment details are sent to the customer by itself — do not repeat them), waiting for the shop to confirm, or refused because the time is not free (with free times instead).',
                'parameters' => self::object([
                    'service_id' => ['type' => 'integer', 'description' => 'service_id from list_reservation_services. Required when there are services.'],
                    'service' => ['type' => 'string', 'description' => 'What the customer wants to reserve, in their words, only when no services are listed.', 'maxLength' => 120],
                    'date' => $date,
                    'time' => $time,
                    'party_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                    'customer_name' => ['type' => 'string', 'description' => 'The name to reserve under, when the customer gave one.', 'maxLength' => 160],
                    'notes' => ['type' => 'string', 'description' => 'Anything the shop should know (a birthday, a high chair).', 'maxLength' => 1000],
                    ...$unsure,
                ], ['date', 'time']),
            ],
            [
                'name' => 'my_reservations',
                'description' => 'The customer\'s open reservations — waiting for the shop, a time the shop offered, held for payment, or confirmed — with their reservation_id, code, time and status. Use it when the customer asks about a reservation, and before changing one.',
                'parameters' => self::object([]),
            ],
            [
                'name' => 'accept_offered_time',
                'description' => 'The customer agrees to the other time the shop offered for their reservation. Confirms it (or holds it for payment when the service asks for one).',
                'parameters' => self::object([
                    'reservation_id' => ['type' => 'integer', 'description' => 'reservation_id from my_reservations.'],
                ], ['reservation_id']),
            ],
            [
                'name' => 'reschedule_reservation',
                'description' => 'Move one of the customer\'s reservations to another day or time of the same service. Checked against the calendar like a new booking.',
                'parameters' => self::object([
                    'reservation_id' => ['type' => 'integer', 'description' => 'reservation_id from my_reservations or request_reservation.'],
                    'date' => $date,
                    'time' => $time,
                    ...$unsure,
                ], ['reservation_id', 'date', 'time']),
            ],
            [
                'name' => 'cancel_reservation',
                'description' => 'Cancel one of the customer\'s reservations, after they confirmed they want it cancelled. The shop is told.',
                'parameters' => self::object([
                    'reservation_id' => ['type' => 'integer', 'description' => 'reservation_id from my_reservations or request_reservation.'],
                    'reason' => ['type' => 'string', 'maxLength' => 255],
                ], ['reservation_id']),
            ],
        ];
    }

    /**
     * The whole catalog, every tool the hub may be told about, independent of
     * any node. Registered once per agent (AiToolHubSync); each run then names
     * the subset its node allows.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public static function all(): array
    {
        $node = new FlowNode(['data' => ['capabilities' => [
            'catalog' => true, 'cart' => true, 'payment' => ['enabled' => true, 'integration_id' => 1],
            'reservations' => ['enabled' => true],
        ]]]);

        return self::build($node, ['catalog' => true, 'reservations' => true]);
    }

    /** @return list<string> */
    public static function names(FlowNode $node, int $tenantId): array
    {
        return array_column(self::definitions($node, $tenantId), 'name');
    }

    /** The definition of one tool, when this node offers it. */
    public static function find(FlowNode $node, int $tenantId, string $tool): ?array
    {
        foreach (self::definitions($node, $tenantId) as $definition) {
            if ($definition['name'] === $tool) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        // The hub's validator (Ajv, see PINGLY-TOOLS-20260928.md) wants
        // `required` on every object, even an empty one, and the root closed.
        return [
            'type' => 'object',
            // An empty PHP array encodes as a JSON list; the schema needs an
            // object, even when a tool takes nothing.
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
