<?php

use App\Enums\Catalog\OrderStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowPaymentStatus;
use App\Models\AiToolCall;
use App\Models\ApiKey;
use App\Models\FlowPayment;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiCallbackRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogFixtures as C;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false, 'ai.tools.enabled' => true]);
});

it('refuses every call while tools are switched off', function () {
    $scene = C::scenario();
    config(['ai.tools.enabled' => false]);

    C::call($scene, 'search_products', ['query' => 'camiseta'])
        ->assertForbidden()
        ->assertJsonPath('code', 'tools_disabled');
});

it('searches the catalog and never offers what is switched off', function () {
    $scene = C::scenario();
    C::product($scene['tenant'], 'Camiseta Preta', ['P' => [5990, 3], 'G' => [5990, 0]]);
    $off = C::product($scene['tenant'], 'Camiseta Branca', ['' => [4990, null]]);
    $off->update(['active' => false]);

    $response = C::call($scene, 'search_products', ['query' => 'camiseta'])->assertOk();

    $items = collect($response->json('result.items'));

    expect($response->json('ok'))->toBeTrue()
        ->and($items->pluck('name')->all())->toBe(['Camiseta Preta – P', 'Camiseta Preta – G'])
        ->and($items->firstWhere('variation', 'G')['available'])->toBeFalse()
        ->and($response->json('message_for_model'))->toContain('R$ 59,90')->toContain('out of stock');
});

it('finds a product without its accents', function () {
    $scene = C::scenario();
    C::product($scene['tenant'], 'Calça Jeans', ['' => [12900, null]]);

    $items = C::call($scene, 'search_products', ['query' => 'calca'])->assertOk()->json('result.items');

    expect($items)->toHaveCount(1)->and($items[0]['name'])->toBe('Calça Jeans');
});

it('builds the cart from catalog prices and checks the stock', function () {
    $scene = C::scenario();
    $product = C::product($scene['tenant'], 'Camiseta Preta', ['G' => [5990, 2]]);
    $variant = $product->variants->first();

    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('result.total', 'R$ 119,80');

    // A third unit is refused with a sentence the model can repeat.
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('result.error', 'insufficient_stock')
        ->assertJsonPath('result.available', 2);

    expect(Order::firstOrFail()->total_cents)->toBe(11980);
});

it('never takes an amount from the model', function () {
    $scene = C::scenario();
    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, null]])->variants->first();

    // The schema has no price; a model that sends one is told so, not ignored.
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1, 'price_cents' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_arguments');

    expect(Order::count())->toBe(0);
});

it('charges the cart total, sends the Pix and answers a retry from the record', function () {
    $scene = C::scenario();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, 10]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk();

    $first = C::call($scene, 'create_payment', [], 'run_1:call_pay')->assertOk();

    expect($first->json('ok'))->toBeTrue()
        ->and($first->json('result.total'))->toBe('R$ 79,80')
        ->and($first->json('message_for_model'))->toContain('do not repeat');

    $payment = FlowPayment::firstOrFail();
    $order = Order::firstOrFail();

    expect($payment->amount_cents)->toBe(7980)
        ->and($payment->order_id)->toBe($order->id)
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(C::$state['charges'])->toHaveCount(1)
        // The copy-and-paste code went out as its own bubble.
        ->and(Fx::sentTexts($scene['conversation']))->toContain(Fx::PIX_CODE);

    // The hub timed out and retried with the same key: one charge, same answer.
    C::call($scene, 'create_payment', [], 'run_1:call_pay')
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect(FlowPayment::count())->toBe(1)->and(C::$state['charges'])->toHaveCount(1);

    // A second, different call for the same cart is not a second charge either.
    C::call($scene, 'create_payment', [], 'run_2:call_pay')
        ->assertOk()
        ->assertJsonPath('result.already_issued', true);

    expect(FlowPayment::count())->toBe(1);

    // The replay wrote nothing: one row per call the hub actually made.
    expect(AiToolCall::count())->toBe(3);
});

it('stops the moment a person takes the conversation', function () {
    $scene = C::scenario();
    $scene['conversation']->update(['status' => ConversationStatus::Active]);

    C::call($scene, 'search_products', ['query' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_with_human');
});

it('only runs the tools the node has ticked', function () {
    $scene = C::scenario(['cart' => false]);

    C::call($scene, 'cart_view')
        ->assertStatus(422)
        ->assertJsonPath('code', 'tool_not_enabled');
});

it('will not spend a reference with another workspace key', function () {
    $scene = C::scenario();

    $stranger = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $tenant->id])->save();
    [, $plain] = ApiKey::issue($tenant, 'Other', $stranger);

    C::call(array_merge($scene, ['key' => $plain]), 'search_products', ['query' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('code', 'conversation_not_found');
});

it('refuses a reference minted for a plain AI agent node', function () {
    $scene = C::scenario();
    $scene['node']->update(['type' => \App\Enums\Flow\NodeType::AIAgent]);

    C::call($scene, 'search_products', ['query' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_not_with_ai');
});

it('takes the stock once when the Pix is paid, however many times it is settled', function () {
    $scene = C::scenario();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, 10]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 3])->assertOk();
    C::call($scene, 'create_payment')->assertOk();

    $payment = FlowPayment::firstOrFail();
    $service = new \App\Services\Flow\FlowPaymentService;

    $service->settle($payment, FlowPaymentStatus::Paid);
    $service->settle($payment->fresh(), FlowPaymentStatus::Paid);

    expect($variant->fresh()->stock)->toBe(7)
        ->and(Order::firstOrFail()->status)->toBe(OrderStatus::Paid)
        ->and(\App\Models\StockMovement::where('reason', 'order_paid')->count())->toBe(1);
});

it('keeps the reference scoped to its conversation', function () {
    $scene = C::scenario();

    $forged = AiCallbackRef::mint($scene['conversation']->id + 1, $scene['node']->id, $scene['agent']->id);
    [$prefix, $body, $mac] = explode('.', $forged);

    C::call($scene, 'search_products', ['query' => 'x'], null, $prefix.'.'.$body.'.'.strrev($mac))
        ->assertForbidden()
        ->assertJsonPath('code', 'callback_ref_invalid');
});

