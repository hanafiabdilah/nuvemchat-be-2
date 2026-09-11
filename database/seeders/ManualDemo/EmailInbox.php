<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Connection;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The shared e-mail inboxes the Webmail chapter is photographed on. */
trait EmailInbox
{
    protected function seedEmail(): void
    {
        $contato = $this->conn['email'];
        $fin = $this->conn['email_fin'];
        $marina = $this->users['marina'];

        $this->emailThread($contato, 'joana.ribeiro@email.example', 'Joana Ribeiro', 'Pedido #48213 — atraso na entrega', [
            ['in', 95, "Olá, equipe Aurora!\n\nFiz o pedido #48213 no dia 28/08 e o rastreio está parado há 5 dias. Poderiam verificar?\n\nSegue print da tela do app.\n\nObrigada,\nJoana", ['print-erro-pagamento.png'], true],
        ]);

        $this->emailThread($contato, 'marcos.vieira@email.example', 'Marcos Vieira', 'Troca de tamanho — Aurora Run', [
            ['in', 1500, "Oi! Comprei o Aurora Run 41 e ficou apertado. Consigo trocar pelo 42?\n\nMarcos", [], false],
            ['out', 1440, "Olá, Marcos!\n\nClaro, a primeira troca é gratuita. Já geramos a etiqueta de postagem — é só levar o pacote a qualquer agência dos Correios em até 7 dias.\n\nAbraço,\nMarina — Loja Aurora", [], false],
            ['in', 240, "Perfeito, postei hoje de manhã. Obrigado!", [], false],
        ], $marina->id);

        $html = <<<'HTML'
<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1f2937">
  <h2 style="color:#4f46e5;margin-bottom:4px">Assessoria Esportiva Movimento</h2>
  <p>Olá, time da Loja Aurora!</p>
  <p>Somos uma assessoria com <b>320 alunos de corrida</b> em São Paulo e gostaríamos de propor uma parceria: desconto exclusivo para nossos atletas e divulgação da marca nas provas do segundo semestre.</p>
  <table style="border-collapse:collapse;width:100%;margin:12px 0">
    <tr><td style="border:1px solid #e5e7eb;padding:8px">Provas no semestre</td><td style="border:1px solid #e5e7eb;padding:8px"><b>6</b></td></tr>
    <tr><td style="border:1px solid #e5e7eb;padding:8px">Alunos ativos</td><td style="border:1px solid #e5e7eb;padding:8px"><b>320</b></td></tr>
  </table>
  <p>Podemos marcar uma conversa esta semana?</p>
  <p>Abraços,<br>Paula Andrade — Coordenação</p>
</div>
HTML;
        $this->emailThread($contato, 'contato@assessoriamovimento.example', 'Assessoria Movimento', 'Proposta de parceria para 2026', [
            ['in', 380, "Olá, time da Loja Aurora! Somos uma assessoria com 320 alunos de corrida e gostaríamos de propor uma parceria. Podemos marcar uma conversa esta semana?", ['catalogo-primavera-2026.pdf'], true, $html],
        ]);

        $this->emailThread($contato, 'cupons@email.example', 'Sabrina Lopes', 'Dúvida sobre o cupom AURORA10', [
            ['in', 2900, "O cupom AURORA10 vale para produtos em promoção?", [], false],
            ['out', 2850, "Oi, Sabrina! O cupom vale para toda a loja, exceto kits. Boas compras!", [], false],
        ], $this->users['juliana']->id);

        $this->emailThread($fin, 'nfe@fornecedoraurora.example', 'Tecidos Brasil Fornecedora', 'Nota fiscal do pedido de compra 7731', [
            ['in', 600, "Prezados, segue em anexo a tabela de preços e a NF-e referente ao pedido 7731.\n\nAtenciosamente,\nDepartamento Financeiro", ['tabela-de-precos-atacado.pdf'], true],
        ]);

        $this->emailThread($fin, 'cobranca@transportadora.example', 'Rápido Log Transportes', 'Fatura de frete — agosto/2026', [
            ['in', 4300, "Olá! A fatura de frete de agosto já está disponível. Vencimento em 15/09.", [], false],
            ['out', 4200, "Recebido, obrigado! Programamos o pagamento para o dia 14.", [], false],
        ], $marina->id);
    }

    /**
     * @param array<int, array> $messages [direction, minutesAgo, body, attachments, unread, html?]
     */
    private function emailThread(Connection $inbox, string $address, string $name, string $subject, array $messages, ?int $replierId = null): void
    {
        $contact = Contact::where('tenant_id', $this->tenant->id)->where('channel', 'email')->where('external_id', $address)->first()
            ?? $this->contact($inbox, $address, $name);

        $conversation = $this->conversation($inbox, $contact, ['status' => 'active', 'external_id' => Str::slug($subject)]);
        $mailbox = $inbox->credentials['email'];

        foreach ($messages as $i => $row) {
            [$direction, $minutesAgo, $body, $attachments, $unread] = $row;
            $html = $row[5] ?? null;
            $at = $this->ago($minutesAgo);
            $incoming = $direction === 'in';

            $email = [
                'uid' => 1000 + $conversation->id * 10 + $i,
                'subject' => $i === 0 ? $subject : "Re: {$subject}",
                'subject_normalized' => Str::lower($subject),
                'from' => $incoming ? $address : $mailbox,
                'to' => [$incoming ? $mailbox : $address],
                'cc' => [],
                'message_id' => '<' . Str::uuid() . '@mail.example>',
            ];

            $stored = [];
            foreach ($attachments as $file) {
                if ($path = $this->media($file)) {
                    $stored[] = ['name' => $file, 'content_type' => str_ends_with($file, '.pdf') ? 'application/pdf' : 'image/png', 'content_id' => null, 'path' => $path];
                }
            }

            if ($stored) {
                $email['attachments'] = $stored;
            }

            if ($html) {
                $htmlPath = 'media/manual-demo/email-' . $conversation->id . '-' . $i . '.html';
                Storage::disk('local')->put($htmlPath, $html);
                $email['html_path'] = $htmlPath;
            }

            $this->msg($conversation, $direction, $at, [
                'body' => $body,
                'attachment' => $stored[0]['path'] ?? null,
                'meta' => ['email' => $email],
                'read_at' => $incoming ? ($unread ? null : $at->copy()->addMinutes(20)) : null,
                'delivery_at' => $incoming ? null : $at->copy()->addSeconds(5),
                'sent_by_user_id' => $incoming ? null : $replierId,
            ]);
        }
    }
}
