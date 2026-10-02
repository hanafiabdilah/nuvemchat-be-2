<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowPayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Str;

/**
 * The shop's product catalog, the "Vendas com IA" flow that sells from it
 * (an "Agente IA com ações" node) and the orders that flow took.
 *
 * Seeded last, after everything else: the capture scripts address flows and
 * nodes by id, so this may only ever append rows.
 */
trait Catalog
{
    /** @var array<string, ProductVariant> by "product-key.variant-name" */
    private array $variants = [];

    protected function seedCatalog(): void
    {
        $this->seedProducts();
        $this->seedSalesFlow();
        $this->seedOrders();
    }

    private function seedProducts(): void
    {
        // key, name, description, photo, active, [variant name => [price cents, stock|null]], days ago
        $products = [
            ['tenis', 'Tênis Aurora Run', 'Tênis de corrida leve (240 g), com cabedal respirável e solado de borracha. Numeração normal: se estiver entre dois números, escolha o maior.',
                'produto-tenis-aurora-run.jpg', true, ['37' => [29990, 4], '38' => [29990, 7], '39' => [29990, 9], '40' => [29990, 6], '41' => [29990, 0], '42' => [29990, 3]], 60],
            ['camiseta', 'Camiseta Dry-Fit Aurora', 'Tecido dry-fit que seca rápido, com proteção UV 50+. Lavar à mão ou no ciclo delicado; não usar amaciante.',
                null, true, ['Preta / P' => [7990, 12], 'Preta / M' => [7990, 18], 'Preta / G' => [7990, 5], 'Branca / M' => [7990, 10], 'Branca / G' => [7990, 8], 'Coral / M' => [8990, 6]], 55],
            ['bone', 'Boné Aurora Classic', 'Boné de algodão com regulagem em velcro. Tamanho único.',
                'produto-bone.jpg', true, [null => [7990, 24]], 50],
            ['mochila', 'Mochila Urbana 22L', 'Mochila impermeável com compartimento acolchoado para notebook de até 15".',
                'produto-mochila-urbana.jpg', true, [null => [18990, 0]], 45],
            ['garrafa', 'Garrafa Térmica 750ml', 'Mantém a bebida gelada por 24 horas e quente por 12. Inox, sem BPA.',
                'produto-garrafa.jpg', true, [null => [8990, 40]], 40],
            ['jaqueta', 'Jaqueta Corta-Vento', 'Jaqueta leve e dobrável, repele garoa. Cabe no próprio bolso.',
                'produto-jaqueta.jpg', true, ['P' => [32990, 3], 'M' => [32990, 5], 'G' => [32990, 2]], 30],
            ['kit', 'Kit Presente Aurora', 'Caixa de presente com boné, garrafa e cartão personalizado. Montado sob encomenda.',
                'produto-kit-presente.jpg', true, [null => [14990, null]], 25],
            ['relogio', 'Relógio Pulse', 'Relógio esportivo com GPS e monitor cardíaco. Coleção antiga: saiu de linha.',
                'produto-relogio-pulse.jpg', false, [null => [45900, 3]], 90],
        ];

        foreach ($products as $position => [$key, $name, $description, $photo, $active, $variants, $days]) {
            $created = $this->now->copy()->subDays($days);
            $product = $this->make(Product::class, [
                'tenant_id' => $this->tenant->id, 'name' => $name, 'description' => $description,
                'image_url' => $photo ? $this->mediaUrl($photo) : null, 'has_variants' => count($variants) > 1,
                'active' => $active, 'position' => $position, 'created_at' => $created, 'updated_at' => $this->now->copy()->subDays(2),
            ]);

            $i = 0;
            foreach ($variants as $variantName => [$price, $stock]) {
                $variantName = $variantName === '' ? null : (string) $variantName;
                $sku = 'AUR-' . strtoupper(substr($key, 0, 4)) . ($variantName ? '-' . strtoupper(Str::slug($variantName, '')) : '');
                $variant = $this->make(ProductVariant::class, [
                    'tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'name' => $variantName, 'sku' => $sku,
                    'price_cents' => $price, 'stock' => $stock, 'active' => true, 'position' => $i++,
                    'created_at' => $created, 'updated_at' => $created,
                ]);
                $this->variants[$key . '.' . ($variantName ?? '')] = $variant;

                if ($stock !== null) {
                    $this->make(StockMovement::class, [
                        'tenant_id' => $this->tenant->id, 'product_variant_id' => $variant->id, 'delta' => $stock + 1,
                        'stock_after' => $stock + 1, 'reason' => 'import', 'user_id' => $this->users['marina']->id,
                        'created_at' => $created, 'updated_at' => $created,
                    ]);
                }
            }
        }
    }

    private function seedSalesFlow(): void
    {
        $text = fn (string $body, int $delay = 0) => ['message_type' => 'text', 'body' => $body, 'delay' => $delay];

        $this->buildFlow('vendas', 'Vendas com IA', [
            ['start', 'start', null, 0, 220],
            ['ai', 'ai_tools', [
                'ai_hub_agent_id' => $this->ai['leo']->id,
                'welcoming_message' => 'Oi! Sou o Leo, da Loja Aurora 👟 Me diz o que você procura que eu vejo o estoque na hora.',
                'answer_first_message' => true, 'service_hours_behavior' => 'always_ai', 'response_delay_seconds' => 8,
                'response_audio' => ['mode' => 'text_only'],
                'capabilities' => [
                    'catalog' => true,
                    'cart' => true,
                    'payment' => ['enabled' => true, 'integration_id' => $this->integrations['openpix']->id, 'method' => 'pix', 'expires_in_minutes' => 30],
                ],
                'follow_up' => ['enabled' => true, 'steps' => [
                    ['delay_minutes' => 30, 'delay_unit' => 'minutes', 'instruction' => 'Pergunte com leveza se ficou alguma dúvida sobre o produto que ele estava vendo.'],
                    ['delay_minutes' => 240, 'delay_unit' => 'hours', 'instruction' => 'Lembre que o carrinho continua separado e ofereça gerar o Pix.'],
                ]],
            ], 300, 160],
            ['paid', 'message', ['label' => 'Pagamento confirmado', 'messages' => [
                $text('Pagamento confirmado! ✅ Seu pedido #{{order_id}} ({{order_items}}) já está sendo separado.'),
                $text('Assim que sair para entrega, eu te mando o código de rastreio 📦', 2)]], 760, 0],
            ['failed', 'message', ['label' => 'Pix não pago', 'messages' => [
                $text('O Pix do seu pedido expirou 😕 Se ainda quiser os produtos, é só me chamar que eu gero outro.')]], 760, 280],
            ['handoff', 'message', ['label' => 'Chama a equipe', 'messages' => [
                $text('Vou chamar alguém da nossa equipe para continuar com você. É rapidinho! 🙂')]], 420, 560],
        ], [
            ['start', 'ai', null], ['ai', 'paid', 'paid'], ['ai', 'failed', 'payment_failed'], ['ai', 'handoff', 'handoff'],
        ], 6);
    }

    private function conversationOf(Contact $contact): ?Conversation
    {
        return Conversation::where('contact_id', $contact->id)->orderByDesc('id')->first();
    }

    private function seedOrders(): void
    {
        $flow = $this->flows['vendas'];
        $node = $this->nodes['vendas.ai'];

        // contact, status, [variant key => qty], minutes ago, payment status (null = no charge), failure reason
        $orders = [
            [$this->pool['tg'][0], 'cancelled', ['kit.' => 1], 6 * 1440, 'failed', 'A OpenPix recusou a cobrança: o valor está abaixo do mínimo da conta.'],
            [$this->pool['wa'][3], 'paid', ['mochila.' => 1], 4 * 1440 + 300, 'paid', null],
            [$this->pool['ig'][2], 'expired', ['jaqueta.M' => 1], 3 * 1440, 'expired', null],
            [$this->people['thiago'], 'paid', ['camiseta.Branca / M' => 3, 'garrafa.' => 1], 2 * 1440 + 90, 'paid', null],
            [$this->people['mariana'], 'paid', ['tenis.42' => 1, 'garrafa.' => 1], 180, 'paid', null],
            [$this->people['joao'], 'awaiting_payment', ['camiseta.Preta / G' => 2, 'bone.' => 1], 12, 'pending', null],
            [$this->people['larissa'], 'open', ['bone.' => 1], 3, null, null],
        ];

        foreach ($orders as $i => [$contact, $status, $lines, $minutes, $paymentStatus, $reason]) {
            $at = $this->ago($minutes);
            $conversation = $this->conversationOf($contact);
            $total = 0;
            foreach ($lines as $key => $qty) {
                $total += $this->variants[$key]->price_cents * $qty;
            }

            $paidAt = $status === 'paid' ? $at->copy()->addMinutes(6) : null;
            $order = $this->make(Order::class, [
                'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation?->id, 'contact_id' => $contact->id,
                'flow_id' => $flow->id, 'flow_node_id' => $node->id, 'status' => $status, 'currency' => 'BRL',
                'total_cents' => $total, 'source' => 'ai_tools', 'paid_at' => $paidAt,
                'closed_at' => in_array($status, ['paid', 'expired', 'cancelled'], true) ? ($paidAt ?? $at->copy()->addMinutes(30)) : null,
                'created_at' => $at, 'updated_at' => $paidAt ?? $at,
            ]);

            foreach ($lines as $key => $qty) {
                $variant = $this->variants[$key];
                $this->make(OrderItem::class, [
                    'order_id' => $order->id, 'product_variant_id' => $variant->id, 'name' => $variant->displayName(),
                    'sku' => $variant->sku, 'unit_price_cents' => $variant->price_cents, 'quantity' => $qty,
                    'line_total_cents' => $variant->price_cents * $qty, 'created_at' => $at, 'updated_at' => $at,
                ]);

                if ($status === 'paid' && $variant->stock !== null) {
                    $this->make(StockMovement::class, [
                        'tenant_id' => $this->tenant->id, 'product_variant_id' => $variant->id, 'delta' => -$qty,
                        'stock_after' => $variant->stock, 'reason' => 'order_paid', 'order_id' => $order->id,
                        'created_at' => $paidAt, 'updated_at' => $paidAt,
                    ]);
                }
            }

            if ($paymentStatus === null) {
                continue;
            }

            $this->make(FlowPayment::class, [
                'tenant_id' => $this->tenant->id, 'integration_id' => $this->integrations['openpix']->id, 'provider' => 'openpix',
                'conversation_id' => $conversation?->id, 'contact_id' => $contact->id, 'flow_id' => $flow->id,
                'flow_node_id' => $node->id, 'order_id' => $order->id,
                'reference' => 'pingly-fp-' . Str::lower((string) Str::ulid()),
                'provider_payment_id' => $paymentStatus === 'failed' ? null : 'opx_' . Str::random(10),
                'method' => 'pix', 'amount_cents' => $total, 'currency' => 'BRL', 'description' => 'Pedido #' . $order->id . ' — Loja Aurora',
                'status' => $paymentStatus,
                'pix_code' => $paymentStatus === 'failed' ? null : '00020126580014BR.GOV.BCB.PIX0136manual-demo-order-' . $i . '5204000053039865802BR5913LOJA AURORA6009SAO PAULO6304ABCD',
                'expires_at' => $at->copy()->addMinutes(30), 'paid_at' => $paidAt,
                'settled_at' => in_array($paymentStatus, ['paid', 'expired', 'failed'], true) ? ($paidAt ?? $at->copy()->addMinutes(30)) : null,
                'failure_reason' => $reason, 'created_at' => $at, 'updated_at' => $paidAt ?? $at,
            ]);
        }
    }
}
