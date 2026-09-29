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
 *  - **only the six tools the hub accepts.** The hub keeps a per-agent
 *    catalog limited to search_products, get_product, cart_add, cart_remove,
 *    cart_view and create_payment (PINGLY-TOOLS-20260928.md); anything else is
 *    refused when the catalog is registered.
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
        // The node is part of the `catalog` feature: a plan without it runs
        // an ai_tools node as a plain AI agent. Same master switch as every
        // other feature gate, so an environment without billing enforcement
        // is not locked out of it.
        if (config('services.billing.enforce')) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant || ! app(SubscriptionGate::class)->feature($tenant, Feature::Catalog->value)) {
                return [];
            }
        }

        return self::build($node);
    }

    /** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    private static function build(FlowNode $node): array
    {
        $caps = AiToolNodes::capabilities($node->data ?? []);
        $tools = [];

        if ($caps['catalog']) {
            $tools[] = [
                'name' => 'search_products',
                'description' => 'Search the shop\'s product catalog. Use it before answering anything about what is sold, prices or availability — never answer those from memory. Returns matching items, each with a variant_id, its price and stock. An empty query lists the first products.',
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

        if ($caps['cart']) {
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

        if ($caps['payment']) {
            $tools[] = [
                'name' => 'create_payment',
                'description' => 'Charge the current cart. Only after the customer confirmed the items and the total. The payment details (Pix QR code and copy-and-paste code, or a payment link) are sent to the customer automatically — do not repeat them in your reply.',
                'parameters' => self::object([]),
            ];
        }

        return $tools;
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
        ]]]);

        return self::build($node);
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
