<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Contact;
use App\Models\FiscalInvoice;
use App\Models\GalleryAsset;
use App\Models\Invoice;
use App\Models\MarketPrice;
use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\Plan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the manual photographs from October 2026 on: exclusive conversations,
 * reopening a thread, returning a customer to the last agent, plans sold
 * yearly, notas fiscais on Pingly's own invoices, gallery files that came from
 * other screens, follow-ups and the Intervalo node, and a connected MCP app.
 *
 * Runs last: it hangs off the people, flows and invoices the other sections
 * already wrote.
 */
trait Recent
{
    protected function seedRecent(): void
    {
        $this->seedExclusiveThread();
        $this->seedReopenableThread();
        $this->seedReturnedThread();
        $this->seedYearlyPrices();
        $this->seedFiscalInvoices();
        $this->seedLinkedGalleryFiles();
        $this->seedIntervalFlow();
        $this->seedMcpConnection();
    }

    /** Juliana's thread with Camila, readable only by Juliana and the owner. */
    private function seedExclusiveThread(): void
    {
        $juliana = $this->users['juliana'];
        $contact = $this->contact($this->conn['wa'], '5511912340010', 'Helena Prado', ['photo' => 'avatar-cliente-2.png']);
        $this->people['helena'] = $contact;

        $conv = $this->conversation($this->conn['wa'], $contact, ['status' => 'active', 'user_id' => $juliana->id]);
        $this->received($conv, $this->ago(48), 'Oi Juliana, preciso falar sobre o reembolso do meu pedido');
        $this->sent($conv, $this->ago(46), 'Oi, Helena! Pode me passar o número do pedido?', ['sent_by_user_id' => $juliana->id]);
        $this->note($conv, $this->ago(45), 'conversation_exclusive_on', ['by' => $juliana->name], "{$juliana->name} made this conversation exclusive.");
        $this->received($conv, $this->ago(44), 'É o #4821. Vou te mandar os dados bancários para o estorno');
        $this->sent($conv, $this->ago(40), 'Perfeito, já registrei aqui. O estorno cai em até 5 dias úteis.', ['sent_by_user_id' => $juliana->id]);
        $this->received($conv, $this->ago(12), 'Obrigada pela ajuda!', true);

        $conv->forceFill(['exclusive_at' => $this->ago(45), 'exclusive_by_user_id' => $juliana->id])->saveQuietly();
    }

    /** Closed three minutes ago on WhatsApp Vendas: the Reabrir button is still live. */
    private function seedReopenableThread(): void
    {
        $marina = $this->users['marina'];
        $contact = $this->contact($this->conn['wa'], '5511912340011', 'Otávio Reis', ['photo' => 'avatar-cliente-5.png']);
        $this->people['otavio'] = $contact;

        $conv = $this->conversation($this->conn['wa'], $contact, ['status' => 'active', 'user_id' => $marina->id]);
        $this->received($conv, $this->ago(20), 'Vocês têm o Relógio Pulse na cor grafite?');
        $this->sent($conv, $this->ago(17), 'Temos sim, Otávio! Separei um para você — o link de pagamento já está aqui na conversa.', ['sent_by_user_id' => $marina->id]);
        $this->received($conv, $this->ago(6), 'Fechado, obrigado!');
        $this->sent($conv, $this->ago(4), 'Obrigada pelo contato! Se precisar de algo, é só chamar por aqui. 💙', ['sent_by_user_id' => $marina->id]);
        $this->note($conv, $this->ago(3), 'conversation_status_changed_by', ['by' => $marina->name, 'from_status' => 'active', 'to_status' => 'resolved'], 'Status changed.');
        $this->resolveAt($conv, $this->ago(3), $marina->id);
    }

    /** A customer who wrote again ten minutes after the chat closed, back with Juliana. */
    private function seedReturnedThread(): void
    {
        $juliana = $this->users['juliana'];
        $contact = $this->contact($this->conn['wa'], '5511912340012', 'Bruno Tavares', ['photo' => 'avatar-cliente-1.png']);

        $conv = $this->conversation($this->conn['wa'], $contact, ['status' => 'active', 'user_id' => $juliana->id]);
        $this->received($conv, $this->ago(75), 'Qual o prazo de troca de um tênis?');
        $this->sent($conv, $this->ago(72), 'Trocas em até 7 dias corridos após o recebimento, Bruno 😊', ['sent_by_user_id' => $juliana->id]);
        $this->note($conv, $this->ago(70), 'conversation_status_changed_by', ['by' => $juliana->name, 'from_status' => 'active', 'to_status' => 'resolved'], 'Status changed.');
        $this->note($conv, $this->ago(61), 'conversation_returned_to_agent', ['agent' => $juliana->name], "Reopened with {$juliana->name}, who last spoke with this contact.");
        $this->received($conv, $this->ago(61), 'Ah, e a troca pode ser feita na loja física?', true);
    }

    /** Every plan also sold yearly, at twelve months less 10%. */
    private function seedYearlyPrices(): void
    {
        foreach (Plan::whereIn('slug', ['starter', 'pro', 'business'])->get() as $plan) {
            $monthly = MarketPrice::where('priceable_type', $plan->getMorphClass())->where('priceable_id', $plan->id)
                ->where('market_code', 'BR')->where('billing_cycle', 'monthly')->first();

            if (! $monthly) {
                continue;
            }

            MarketPrice::query()->updateOrCreate(
                ['priceable_type' => $plan->getMorphClass(), 'priceable_id' => $plan->id, 'market_code' => 'BR', 'billing_cycle' => 'yearly'],
                ['amount_cents' => (int) (round($monthly->amount_cents * 12 * 0.9 / 100) * 100 - 10), 'currency' => 'BRL'],
            );
        }
    }

    /** Notas fiscais on Pingly's monthly invoices, and the tomador's address. */
    private function seedFiscalInvoices(): void
    {
        $this->tenant->forceFill(['billing_address' => [
            'cep' => '01310100', 'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'complemento' => 'Sala 42',
            'bairro' => 'Bela Vista', 'cidade' => 'São Paulo', 'codigo_cidade' => '3550308', 'estado' => 'SP',
        ]])->saveQuietly();

        $paid = Invoice::where('tenant_id', $this->tenant->id)->where('status', 'paid')->orderByDesc('created_at')->get();
        $numbers = ['2026/000418', '2026/000371', '2026/000329', '2026/000287', '2026/000254', '2026/000233', '2026/000201'];

        foreach ($paid as $i => $invoice) {
            $issued = $i > 0;
            $at = $invoice->paid_at?->copy()->addMinutes(20) ?? $invoice->created_at;

            $this->make(FiscalInvoice::class, [
                'invoice_id' => $invoice->id, 'tenant_id' => $this->tenant->id, 'provider' => 'plugnotas',
                'status' => $issued ? 'issued' : 'processing', 'attempt' => 1,
                'reference' => 'pingly-inv-' . $invoice->id, 'provider_id' => $issued ? 'pn_' . Str::lower(Str::random(16)) : null,
                'protocol' => 'prot-' . $invoice->id, 'number' => $issued ? $numbers[$i % count($numbers)] : null,
                'verification_code' => $issued ? strtoupper(Str::random(8)) : null, 'amount_cents' => $invoice->amount_cents,
                'description' => 'Licença de uso do Pingly', 'submitted_at' => $at, 'checked_at' => $at,
                'issued_at' => $issued ? $at->copy()->addMinutes(9) : null, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    /** Files the gallery lists without keeping: from a flow, a campaign and a chat. */
    private function seedLinkedGalleryFiles(): void
    {
        $published = [
            ['flow', 'Tabela de tamanhos.jpg', 'produto-tenis-aurora-run.jpg', 'image/jpeg', 'image', 2],
            ['campaign', 'Banner Semana Aurora.jpg', 'post-frete-gratis.jpg', 'image/jpeg', 'image', 4],
            ['catalog', 'Garrafa térmica.jpg', 'produto-garrafa.jpg', 'image/jpeg', 'image', 9],
            ['upload', 'Política de trocas.pdf', 'catalogo-primavera-2026.pdf', 'application/pdf', 'document', 15],
        ];

        foreach ($published as [$origin, $name, $file, $mime, $type, $days]) {
            $path = "uploads/{$this->tenant->id}/{$name}";
            if ($this->mediaDir && is_file($this->mediaDir . '/' . $file)) {
                Storage::disk('public')->put($path, file_get_contents($this->mediaDir . '/' . $file));
            }

            $this->make(GalleryAsset::class, [
                'tenant_id' => $this->tenant->id, 'origin' => $origin, 'uuid' => (string) Str::uuid(),
                'public_filename' => $name, 'uploaded_by_user_id' => $this->users['marina']->id, 'name' => $name,
                'path' => $path, 'mime_type' => $mime, 'type' => $type, 'size_bytes' => 380000 + $days * 12000,
                'checksum' => hash('sha256', $path), 'created_at' => $this->now->copy()->subDays($days),
            ]);
        }

        // A photo Juliana sent in a chat: listed while the message keeps it.
        $thiago = $this->people['thiago'];
        $conv = \App\Models\Conversation::where('contact_id', $thiago->id)->first();
        if ($conv && ($path = $this->media('produto-relogio-pulse.jpg', 'media/manual-demo-sent'))) {
            $message = $this->sent($conv, $this->ago(52), 'Olha o Pulse na cor grafite 👇', [
                'message_type' => 'image', 'attachment' => $path, 'sent_by_user_id' => $this->users['juliana']->id,
            ]);
            $this->make(GalleryAsset::class, [
                'tenant_id' => $this->tenant->id, 'origin' => 'message', 'message_id' => $message->id, 'uuid' => (string) Str::uuid(),
                'public_filename' => 'relogio-pulse-grafite.jpg', 'uploaded_by_user_id' => $this->users['juliana']->id,
                'name' => 'relogio-pulse-grafite.jpg', 'path' => $path, 'mime_type' => 'image/jpeg', 'type' => 'image',
                'size_bytes' => 412000, 'checksum' => hash('sha256', $path), 'created_at' => $this->ago(52),
            ]);
        }
    }

    /** "Pós-venda": a thank-you, a day's Intervalo, then a review request. */
    private function seedIntervalFlow(): void
    {
        $text = fn (string $body, int $delay = 0) => ['message_type' => 'text', 'body' => $body, 'delay' => $delay];

        $this->buildFlow('posvenda', 'Pós-venda', [
            ['start', 'start', null, 0, 160],
            ['obrigado', 'message', ['label' => 'Agradecimento', 'messages' => [
                $text('Obrigada pela compra, {{contact.name}}! 💙 Seu pedido já está a caminho.')]], 300, 120],
            ['espera', 'interval', ['seconds' => 7200, 'unit' => 'hours', 'presence' => false], 640, 140],
            ['avaliacao', 'message', ['label' => 'Pedir avaliação', 'messages' => [
                $text('Já deu tempo de testar? Conta pra gente de 1 a 5 o que achou 😊', 3)]], 940, 120],
        ], [
            ['start', 'obrigado', null], ['obrigado', 'espera', null], ['espera', 'avaliacao', null],
        ], 3);
    }

    /** Marina connected Claude Desktop to the workspace's flows and leads. */
    private function seedMcpConnection(): void
    {
        $clients = [
            ['Claude', 'https://claude.ai', ['mcp:flows.read', 'mcp:flows.write', 'mcp:media.read', 'mcp:leads.read', 'mcp:statistics.read'], 35, 2],
            ['Codex', null, ['mcp:flows.read'], 12, 4300],
        ];

        foreach ($clients as $i => [$name, $uri, $scopes, $days, $usedMinutes]) {
            $client = $this->make(McpClient::class, [
                'client_id' => 'mcp_demo_' . $i, 'client_name' => $name, 'source' => 'dcr',
                'redirect_uris' => ['http://127.0.0.1:33418/callback'], 'client_uri' => $uri,
            ]);

            $this->make(McpConnection::class, [
                'tenant_id' => $this->tenant->id, 'user_id' => $this->users['marina']->id, 'mcp_client_id' => $client->id,
                'client_name' => $name, 'scopes' => $scopes, 'last_used_at' => $this->ago($usedMinutes),
                'last_used_ip' => '177.10.0.' . (20 + $i), 'created_at' => $this->now->copy()->subDays($days),
            ]);
        }
    }
}
