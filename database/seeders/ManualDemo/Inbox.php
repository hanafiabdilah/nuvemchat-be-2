<?php

namespace Database\Seeders\ManualDemo;

use App\Models\ConversationNote;
use App\Models\FlowState;
use Illuminate\Support\Facades\DB;

/**
 * The conversations the inbox chapters are photographed on: one thread per
 * state the manual has to explain (mine / someone else's / queue / AI / group /
 * window closing / muted / notes / every bubble type).
 */
trait Inbox
{
    protected function seedInbox(): void
    {
        $this->seedPeople();
        $this->threadMariana();
        $this->threadJoao();
        $this->threadBia();
        $this->threadsTeam();
        $this->threadsQueue();
        $this->threadsAi();
        $this->threadGroup();
        $this->threadsAttention();
        $this->threadsResolvedRecently();
    }

    private function seedPeople(): void
    {
        $c = $this->conn;
        $defs = [
            'mariana' => ['apiway', '5511987650001', 'Mariana Souza', ['photo' => 'avatar-cliente-8.png']],
            'joao' => ['wa', '5511976540002', 'João Pedro Almeida', ['photo' => 'avatar-cliente-1.png']],
            'bia' => ['ig', '17841455500001', 'Bia Store', ['username' => 'bia.store', 'photo' => 'avatar-cliente-6.png']],
            'lucas' => ['tg', '600000101', 'Lucas Ferreira', ['username' => 'lucasferreira']],
            'camila' => ['widget', 'visitor_7f3a91', 'Camila Rocha', []],
            'marcos' => ['wa', '5511921090007', 'Marcos Vieira', ['photo' => 'avatar-cliente-5.png']],
            'pedro' => ['wa', '5511965430003', 'Pedro Henrique Costa', ['photo' => 'avatar-cliente-3.png']],
            'fernanda' => ['ig', '17841455500002', 'Fernanda Lima', ['username' => 'fe.lima', 'photo' => 'avatar-cliente-2.png']],
            'gustavo' => ['ig', '17841455500003', 'Gustavo Martins', ['username' => 'gustavo.run']],
            'ricardo' => ['wa', '5511954320004', 'Ricardo Gomes', []],
            'aline' => ['apiway', '5511943210005', 'Aline Castro', ['photo' => 'avatar-cliente-4.png']],
            'roberto' => ['tg', '600000102', 'Roberto Nunes', ['username' => 'robertonunes', 'photo' => 'avatar-cliente-9.png']],
            'patricia' => ['wa', '5511932100006', 'Patrícia Duarte', []],
            'thiago' => ['tg', '600000103', 'Thiago Barbosa', ['username' => 'thiagob', 'photo' => 'avatar-cliente-7.png']],
            'larissa' => ['tiktok', 'tt_user_9001', 'Larissa Mota', ['username' => 'larimota']],
            'eduardo' => ['widget', 'visitor_2b8c44', 'Eduardo Ramos', []],
            'vanessa' => ['wa', '5511910980008', 'Vanessa Teles', []],
            'diego' => ['ig', '17841455500004', 'Diego Araújo', ['username' => 'diego.araujo']],
            'sergio' => ['apiway', '5511911110001', 'Sérgio Almeida', []],
            'renata' => ['apiway', '5511911110002', 'Renata Campos', []],
            'paulo' => ['apiway', '5511911110003', 'Paulo Vieira', []],
        ];

        foreach ($defs as $key => [$connection, $external, $name, $extra]) {
            $this->people[$key] = $this->contact($c[$connection], $external, $name, $extra);
        }

        $this->tagContact($this->people['mariana'], ['vip', 'recorrente']);
        $this->tagContact($this->people['bia'], ['atacado']);
        $this->tagContact($this->people['aline'], ['atacado', 'recorrente']);
        $this->tagContact($this->people['fernanda'], ['reclamacao']);
    }

    private function threadMariana(): void
    {
        $p = $this->people['mariana'];
        $me = $this->users['marina'];
        $flow = $this->flows['inicial'] ?? null;
        $sofia = $this->ai['sofia'] ?? null;

        // Two earlier visits, so "Carregar conversa anterior" has something to load.
        foreach ([[45, 'juliana', 'Vocês têm a Mochila Urbana em preto?', 'Temos sim! Separei uma para você 😉'],
                  [20, 'rafael', 'O meu pedido #47102 já saiu para entrega?', 'Saiu hoje cedo! Chega amanhã até as 18h.']] as [$days, $agent, $ask, $answer]) {
            $old = $this->conversation($this->conn['apiway'], $p, ['status' => 'active', 'user_id' => $this->users[$agent]->id]);
            $t = $this->brt($days, 15, 10);
            $this->received($old, $t, $ask);
            $this->sent($old, $t->copy()->addMinutes(3), $answer, ['sent_by_user_id' => $this->users[$agent]->id]);
            $this->received($old, $t->copy()->addMinutes(6), 'Perfeito, obrigada!');
            $this->resolveAt($old, $t->copy()->addMinutes(9), $this->users[$agent]->id);
        }

        $conv = $this->conversation($this->conn['apiway'], $p, ['status' => 'active', 'user_id' => $me->id]);
        $this->tagConversation($conv, ['orcamento']);

        $y = fn (int $h, int $m) => $this->brt(1, $h, $m);
        $first = $this->received($conv, $y(16, 2), 'Oi! Tudo bem? Vi o anúncio de vocês no Instagram 😍');
        $this->react($first, '👍', 'outgoing');
        $this->note($conv, $y(16, 4), 'conversation_assigned', ['to' => $me->name], "{$me->name} took this conversation.");
        $this->sent($conv, $y(16, 5), 'Olá, Mariana! Tudo ótimo 😊 Como posso ajudar?', ['sent_by_user_id' => $me->id]);
        $photo = $this->received($conv, $y(16, 7), 'Esse é o modelo que eu quero', false, [
            'message_type' => 'image', 'attachment' => $this->media('produto-tenis-aurora-run.jpg'),
        ]);
        $this->react($photo, '❤️', 'outgoing');
        $this->react($photo, '😮', 'incoming', $p->id);
        $this->sent($conv, $y(16, 9), 'Temos em estoque! Posso te mandar o catálogo completo?', [
            'sent_by_user_id' => $me->id, 'replied_message_id' => $photo->id,
            'edited_at' => $y(16, 10), 'read_at' => null,
        ]);
        $this->note($conv, $y(17, 30), 'call_missed', [], 'Missed call.');

        $t = fn (int $minutes) => $this->ago($minutes);
        $this->received($conv, $t(52), null ?? '', false, [
            'message_type' => 'audio', 'body' => null, 'attachment' => $this->media('audio-cliente-tamanho.m4a'),
            'meta' => ['transcription' => ['text' => 'Oi, tudo bem? Queria saber se o tênis Aurora Run tem no tamanho quarenta e dois, e se dá pra entregar até sexta-feira aqui em Campinas. Se tiver na cor coral eu prefiro, mas pode ser a azul também.']],
        ]);
        $this->sent($conv, $t(51), 'Confira a coleção completa: https://lojaaurora.example/colecao-primavera', [
            'sent_by_flow_id' => $flow?->id,
        ]);
        $this->msg($conv, 'out', $t(50), [
            'message_type' => 'audio', 'attachment' => $this->media('audio-resposta-ia.m4a'),
            'sent_by_flow_id' => $flow?->id, 'sent_by_ai_hub_agent_id' => $sofia?->id,
            'delivery_at' => $t(50), 'read_at' => $t(49),
            'meta' => ['transcription' => ['text' => 'Tem sim! O Aurora Run no 42 está disponível, e para Campinas a entrega chega em até dois dias úteis. Quer que eu separe um para você?']],
        ]);
        $this->msg($conv, 'out', $t(47), [
            'message_type' => 'document', 'body' => 'Segue o catálogo da coleção Primavera 📘',
            'attachment' => $this->media('catalogo-primavera-2026.pdf'), 'sent_by_user_id' => $me->id,
        ]);
        $this->received($conv, $t(44), '', false, [
            'message_type' => 'location', 'body' => null,
            'meta' => ['Message' => ['locationMessage' => ['degreesLatitude' => -22.905560, 'degreesLongitude' => -47.060830, 'name' => 'Casa']]],
        ]);
        $this->received($conv, $t(43), '', false, [
            'message_type' => 'contact', 'body' => null,
            'meta' => ['Message' => ['contactMessage' => [
                'displayName' => 'Carlos Souza',
                'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:Carlos Souza\nTEL;type=CELL;waid=5511987654321:+55 11 98765-4321\nEND:VCARD",
            ]]],
        ]);
        $this->received($conv, $t(40), '', false, [
            'message_type' => 'sticker', 'body' => null, 'attachment' => $this->media('avatar-grupo-corrida.png'),
        ]);
        $this->received($conv, $t(38), 'Olha o vídeo que vi no perfil de vocês!', false, [
            'message_type' => 'video', 'attachment' => $this->media('video-aurora-run.webm'),
        ]);
        $this->sent($conv, $t(30), 'Mensagem enviada por engano', ['sent_by_user_id' => $me->id, 'unsend_at' => $t(29)]);
        $this->received($conv, $t(20), 'Perfeito! Vou querer o 42 na cor coral. Consegue entregar até sexta?', false, [
            'starred_at' => $t(19),
        ]);
        $this->sent($conv, $t(12), 'Consigo sim! Vou gerar o link de pagamento para você 😉', ['sent_by_user_id' => $me->id]);

        foreach ([['marina', 'Cliente VIP — oferecer frete grátis na próxima compra.', 180], ['rafael', 'Prefere contato à tarde. Já comprou 3 vezes este ano.', 60]] as [$who, $body, $ago]) {
            $this->make(ConversationNote::class, [
                'conversation_id' => $conv->id, 'user_id' => $this->users[$who]->id, 'body' => $body,
                'created_at' => $this->ago($ago), 'updated_at' => $who === 'rafael' ? $this->ago($ago - 20) : $this->ago($ago),
            ]);
        }
    }

    private function threadJoao(): void
    {
        $p = $this->people['joao'];
        $me = $this->users['marina'];
        $flow = $this->flows['inicial'] ?? null;
        $conv = $this->conversation($this->conn['wa'], $p, ['status' => 'active', 'user_id' => $me->id]);
        $base = $this->ago(1375);
        $at = fn (int $m) => $base->copy()->addMinutes($m);

        $this->received($conv, $at(0), 'Boa tarde! Queria ver opções de tênis para corrida');
        $this->msg($conv, 'out', $at(1), [
            'message_type' => 'interactive', 'body' => 'Como podemos ajudar hoje?', 'sent_by_flow_id' => $flow?->id,
            'delivery_at' => $at(1), 'read_at' => $at(2),
            'meta' => ['interactive' => [
                'type' => 'button', 'header' => ['type' => 'text', 'text' => 'Loja Aurora'],
                'body' => ['text' => 'Como podemos ajudar hoje?'], 'footer' => ['text' => 'Atendimento 24h'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'btn_comprar', 'title' => 'Quero comprar']],
                    ['type' => 'reply', 'reply' => ['id' => 'btn_suporte', 'title' => 'Preciso de suporte']],
                    ['type' => 'reply', 'reply' => ['id' => 'btn_atendente', 'title' => 'Falar com atendente']],
                ]],
            ]],
        ]);
        $this->received($conv, $at(2), 'Quero comprar', false, [
            'message_type' => 'interactive',
            'meta' => ['changes' => [['value' => ['messages' => [['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn_comprar', 'title' => 'Quero comprar']]]]]]]],
        ]);
        $this->msg($conv, 'out', $at(3), [
            'message_type' => 'interactive', 'body' => 'Escolha uma categoria para ver os produtos:', 'sent_by_user_id' => $me->id,
            'delivery_at' => $at(3), 'read_at' => $at(4),
            'meta' => ['interactive' => [
                'type' => 'list', 'body' => ['text' => 'Escolha uma categoria para ver os produtos:'],
                'action' => ['button' => 'Ver categorias', 'sections' => [
                    ['title' => 'Calçados', 'rows' => [
                        ['id' => 'cat_corrida', 'title' => 'Tênis de corrida', 'description' => 'Aurora Run, Aurora Trail'],
                        ['id' => 'cat_casual', 'title' => 'Tênis casual', 'description' => 'Linha Urban'],
                    ]],
                    ['title' => 'Acessórios', 'rows' => [
                        ['id' => 'cat_relogios', 'title' => 'Relógios', 'description' => 'Relógio Pulse'],
                        ['id' => 'cat_mochilas', 'title' => 'Mochilas', 'description' => 'Mochila Urbana 22L'],
                    ]],
                ]],
            ]],
        ]);
        $this->received($conv, $at(5), 'Tênis de corrida', false, [
            'message_type' => 'interactive',
            'meta' => ['changes' => [['value' => ['messages' => [['interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'cat_corrida', 'title' => 'Tênis de corrida', 'description' => 'Aurora Run, Aurora Trail']]]]]]]],
        ]);
        $cards = [];
        foreach ([['produto-tenis-aurora-run.jpg', 'Tênis Aurora Run — R$ 299,90', 'run'], ['produto-relogio-pulse.jpg', 'Relógio Pulse — R$ 459,00', 'pulse'], ['produto-garrafa.jpg', 'Garrafa Térmica 750ml — R$ 89,90', 'garrafa']] as $i => [$img, $text, $slug]) {
            $cards[] = [
                'card_index' => $i, 'type' => 'cta_url',
                'header' => ['type' => 'image', 'image' => ['link' => $this->mediaUrl($img)]],
                'body' => ['text' => $text],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => 'Ver produto', 'url' => "https://lojaaurora.example/p/{$slug}"]],
            ];
        }
        $carousel = $this->msg($conv, 'out', $at(6), [
            'message_type' => 'interactive', 'body' => 'Separei 3 sugestões para você 👇', 'sent_by_user_id' => $me->id,
            'delivery_at' => $at(6), 'read_at' => $at(7),
            'meta' => ['interactive' => ['type' => 'carousel', 'body' => ['text' => 'Separei 3 sugestões para você 👇'], 'action' => ['cards' => $cards]]],
        ]);
        $carousel->forceFill(['starred_at' => $at(8)])->saveQuietly();
        $this->received($conv, $at(15), 'Gostei do Aurora Run! Tem na cor coral? 🧡');
    }

    private function threadBia(): void
    {
        $p = $this->people['bia'];
        $me = $this->users['marina'];
        $conv = $this->conversation($this->conn['ig'], $p, ['status' => 'active', 'user_id' => $me->id]);
        $this->tagConversation($conv, ['atacado', 'orcamento']);

        $this->received($conv, $this->ago(70), 'Oi! Vocês fazem revenda? Tenho uma loja em Santos 🛍️');
        $reel = $this->received($conv, $this->ago(68), 'https://www.instagram.com/reel/C9xAuroraRun1/', false, [
            'message_type' => 'instagram_share',
            'meta' => ['instagram_share' => ['kind' => 'reel', 'permalink' => 'https://www.instagram.com/reel/C9xAuroraRun1/', 'media_id' => '18000000000000001', 'caption' => 'Treino de hoje com o Aurora Run 🔥']],
        ]);
        $reel->forceFill(['starred_at' => $this->ago(60)])->saveQuietly();
        $this->received($conv, $this->ago(66), 'Look do dia com a jaqueta corta-vento da @aurora.esportes 💨', false, [
            'message_type' => 'instagram_share',
            'meta' => ['instagram_share' => ['kind' => 'post', 'permalink' => null, 'media_id' => '17900000000000002', 'caption' => 'Look do dia com a jaqueta corta-vento da @aurora.esportes 💨']],
        ]);
        $this->msg($conv, 'out', $this->ago(45), [
            'message_type' => 'image', 'attachment' => $this->media('produto-kit-presente.jpg'), 'sent_by_user_id' => $me->id,
            'delivery_at' => $this->ago(45), 'read_at' => $this->ago(44),
        ]);
        $this->sent($conv, $this->ago(44), 'Fazemos sim! Temos condições especiais para revenda a partir de 10 peças 😊', ['sent_by_user_id' => $me->id]);
        $this->received($conv, $this->ago(40), 'Ótimo! Me manda a tabela de atacado, por favor.');
    }

    private function threadsTeam(): void
    {
        $juliana = $this->users['juliana'];
        $rafael = $this->users['rafael'];
        $marina = $this->users['marina'];

        $lucas = $this->conversation($this->conn['tg'], $this->people['lucas'], ['status' => 'active', 'user_id' => $rafael->id]);
        $this->received($lucas, $this->ago(60), 'Olá, comprei um relógio Pulse e ele não está sincronizando com o app');
        $this->note($lucas, $this->ago(58), 'conversation_assigned', ['to' => $juliana->name], "{$juliana->name} took this conversation.");
        $this->sent($lucas, $this->ago(57), 'Oi, Lucas! Vou verificar com o time técnico, só um instante.', ['sent_by_user_id' => $juliana->id]);
        $this->note($lucas, $this->ago(40), 'conversation_transferred', ['from' => $juliana->name, 'to' => $rafael->name], "{$juliana->name} transferred this conversation to {$rafael->name}.");
        $this->sent($lucas, $this->ago(35), 'Lucas, aqui é o Rafael, do suporte técnico. Qual é a versão do app no seu celular?', ['sent_by_user_id' => $rafael->id]);
        $this->received($lucas, $this->ago(18), 'É a versão 3.2.1, no Android');
        $this->tagConversation($lucas, ['suporte']);

        $thiago = $this->conversation($this->conn['tg'], $this->people['thiago'], ['status' => 'active', 'user_id' => $juliana->id]);
        $this->received($thiago, $this->ago(80), 'Bom dia! Qual o prazo de entrega para Curitiba?');
        $this->sent($thiago, $this->ago(78), 'Bom dia, Thiago! Para Curitiba são 3 dias úteis 🚚', ['sent_by_user_id' => $juliana->id]);
        $this->received($thiago, $this->ago(55), 'Show, vou fechar o pedido então');

        $eduardo = $this->conversation($this->conn['widget'], $this->people['eduardo'], ['status' => 'active', 'user_id' => $juliana->id]);
        $this->received($eduardo, $this->ago(30), 'Oi, vocês têm loja física em São Paulo?');
        $this->sent($eduardo, $this->ago(25), 'Temos sim, Eduardo! Av. Paulista, 1000 — aberta de segunda a sábado.', ['sent_by_user_id' => $juliana->id]);

        $aline = $this->conversation($this->conn['apiway'], $this->people['aline'], ['status' => 'active', 'user_id' => $marina->id]);
        $this->tagConversation($aline, ['atacado']);
        $this->received($aline, $this->ago(160), 'Oi Marina, bom dia!');
        $this->sent($aline, $this->ago(150), 'Bom dia, Aline! Tudo bem? 😊', ['sent_by_user_id' => $marina->id]);
        $this->note($aline, $this->ago(140), 'call_answered', ['duration' => 184], 'Call answered.');
        $this->received($aline, $this->ago(9), 'Consegue me mandar a tabela de atacado atualizada?', true);
        $this->received($aline, $this->ago(7), 'Vou fechar um pedido grande para a loja', true);
        $this->received($aline, $this->ago(5), 'Preciso para hoje 🙏', true);

        $patricia = $this->conversation($this->conn['wa'], $this->people['patricia'], ['status' => 'active', 'user_id' => $marina->id, 'muted_at' => $this->ago(100)]);
        $this->received($patricia, $this->ago(120), 'Oi! Vocês entregam no mesmo dia?');
        $this->sent($patricia, $this->ago(110), 'Para a cidade de São Paulo, sim — pedidos até as 12h 😉', ['sent_by_user_id' => $marina->id]);
        $this->received($patricia, $this->ago(70), 'Que ótimo! Vou pensar e te aviso');
        $this->make(ConversationNote::class, [
            'conversation_id' => $patricia->id, 'user_id' => $marina->id,
            'body' => 'Silenciada: cliente pediu para retornar só na semana que vem.',
            'created_at' => $this->ago(100), 'updated_at' => $this->ago(100),
        ]);

        $roberto = $this->conversation($this->conn['tg'], $this->people['roberto'], ['status' => 'active', 'user_id' => $marina->id]);
        $this->received($roberto, $this->ago(1840), 'Consegue trocar o tamanho do meu pedido para 41?');
        $this->sent($roberto, $this->ago(1800), 'Troca feita, Roberto! O novo par sai amanhã. 👍', ['sent_by_user_id' => $marina->id]);

        $larissa = $this->conversation($this->conn['tiktok'], $this->people['larissa'], ['status' => 'active', 'user_id' => $marina->id]);
        $this->received($larissa, $this->ago(1830), 'Vi o vídeo de vocês no TikTok! Onde compro o boné?');
        $this->sent($larissa, $this->ago(1825), 'Oi, Larissa! Pelo site ou aqui mesmo 😄', ['sent_by_user_id' => $marina->id]);
        $this->received($larissa, $this->ago(1800), 'Pode ser aqui! Tem na cor preta?');
    }

    private function threadsQueue(): void
    {
        $inicial = $this->flows['inicial'] ?? null;

        $pedro = $this->conversation($this->conn['wa'], $this->people['pedro'], ['status' => 'pending']);
        $this->received($pedro, $this->ago(18), 'Oi, boa tarde');
        $this->sent($pedro, $this->ago(18), 'Olá, Pedro Henrique Costa! 👋 Que bom ter você aqui.', ['sent_by_flow_id' => $inicial?->id]);
        $this->msg($pedro, 'out', $this->ago(17), [
            'message_type' => 'interactive', 'body' => 'Como podemos ajudar hoje?', 'sent_by_flow_id' => $inicial?->id,
            'delivery_at' => $this->ago(17), 'read_at' => $this->ago(16),
            'meta' => ['interactive' => [
                'type' => 'button', 'header' => ['type' => 'text', 'text' => 'Loja Aurora'],
                'body' => ['text' => 'Como podemos ajudar hoje?'], 'footer' => ['text' => 'Atendimento 24h'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'btn_comprar', 'title' => 'Quero comprar']],
                    ['type' => 'reply', 'reply' => ['id' => 'btn_suporte', 'title' => 'Preciso de suporte']],
                    ['type' => 'reply', 'reply' => ['id' => 'btn_atendente', 'title' => 'Falar com atendente']],
                ]],
            ]],
        ]);
        $this->received($pedro, $this->ago(16), 'Quero comprar', false, [
            'message_type' => 'interactive',
            'meta' => ['changes' => [['value' => ['messages' => [['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn_comprar', 'title' => 'Quero comprar']]]]]]]],
        ]);
        $this->sent($pedro, $this->ago(15), 'Ótimo! Qual é o seu e-mail para enviarmos as ofertas?', ['sent_by_flow_id' => $inicial?->id]);

        if ($inicial && isset($this->nodes['inicial.response'])) {
            $this->make(FlowState::class, [
                'conversation_id' => $pedro->id, 'flow_id' => $inicial->id,
                'current_node_id' => $this->nodes['inicial.response']->id,
                'state_data' => ['_response_sent_' . $this->nodes['inicial.response']->id => true, 'contact_name' => 'Pedro Henrique Costa'],
                'status' => 'running', 'created_at' => $this->ago(18), 'updated_at' => $this->ago(15),
            ]);
        }

        $fernanda = $this->conversation($this->conn['ig'], $this->people['fernanda'], [
            'status' => 'pending', 'needs_human' => true, 'handoff_reason' => 'error', 'handoff_at' => $this->ago(8),
        ]);
        $this->tagConversation($fernanda, ['reclamacao', 'urgente']);
        $this->received($fernanda, $this->ago(14), 'Quero trocar um produto que veio com defeito');
        $this->sent($fernanda, $this->ago(13), 'Sinto muito, Fernanda! Pode me enviar uma foto do defeito?', [
            'sent_by_flow_id' => $this->flows['suporte']->id ?? null, 'sent_by_ai_hub_agent_id' => $this->ai['suporte']->id ?? null,
        ]);
        $this->received($fernanda, $this->ago(8), 'Quero falar com uma pessoa, por favor', true);

        $gustavo = $this->conversation($this->conn['ig'], $this->people['gustavo'], ['status' => 'pending']);
        $this->received($gustavo, $this->ago(34), 'Oi, meu pedido chegou hoje');
        $this->received($gustavo, $this->ago(32), 'A caixa chegou assim 😕', true, [
            'message_type' => 'image', 'attachment' => $this->media('foto-cliente-caixa.jpg'),
        ]);
    }

    private function threadsAi(): void
    {
        $suporte = $this->flows['suporte'] ?? null;
        $agent = $this->ai['suporte'] ?? null;
        $aiNode = $this->nodes['suporte.ai'] ?? null;

        $camila = $this->conversation($this->conn['widget'], $this->people['camila'], ['status' => 'ai_handling']);
        $this->received($camila, $this->ago(9), 'Oi, meu pedido #48213 ainda não chegou');
        $this->sent($camila, $this->ago(8), 'Oi, Camila! Sou a assistente da Loja Aurora. Pode me confirmar o CPF usado na compra?', [
            'sent_by_flow_id' => $suporte?->id, 'sent_by_ai_hub_agent_id' => $agent?->id,
        ]);
        $last = $this->received($camila, $this->ago(3), 'Já faz 10 dias que comprei');

        if ($suporte && $aiNode) {
            $this->make(FlowState::class, [
                'conversation_id' => $camila->id, 'flow_id' => $suporte->id, 'current_node_id' => $aiNode->id,
                'state_data' => [
                    '_ai_debounce_token_' . $aiNode->id => 'manual-demo-token',
                    '_ai_last_processed_message_id_' . $aiNode->id => $last->id - 1,
                ],
                'status' => 'running', 'created_at' => $this->ago(9), 'updated_at' => $this->ago(3),
            ]);
        }

        $marcos = $this->conversation($this->conn['wa'], $this->people['marcos'], ['status' => 'ai_handling']);
        $this->received($marcos, $this->ago(26), 'Vocês aceitam Pix parcelado?');
        $this->sent($marcos, $this->ago(25), 'Aceitamos Pix à vista com 5% de desconto, ou cartão em até 6x sem juros 😊', [
            'sent_by_flow_id' => $this->flows['inicial']->id ?? null, 'sent_by_ai_hub_agent_id' => $this->ai['sofia']->id ?? null,
        ]);
        $this->received($marcos, $this->ago(22), 'Legal, obrigado!');
    }

    private function threadGroup(): void
    {
        $group = $this->contact($this->conn['apiway'], '120363041234567890@g.us', 'Revendedores Aurora', ['is_group' => true, 'photo' => 'avatar-grupo-revenda.png']);
        $conv = $this->conversation($this->conn['apiway'], $group, ['status' => 'pending', 'type' => 'group']);
        $members = [$this->people['sergio'], $this->people['renata'], $this->people['paulo']];

        foreach ($members as $member) {
            DB::table('conversation_participants')->insert([
                'conversation_id' => $conv->id, 'contact_id' => $member->id, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        $this->received($conv, $this->ago(90), 'Bom dia, pessoal! A nova coleção já chegou?', false, ['contact_id' => $members[0]->id]);
        $m = $this->received($conv, $this->ago(85), 'Chegou sim! Vi no Instagram 😍', false, ['contact_id' => $members[1]->id]);
        $this->react($m, '🔥', 'incoming', $members[0]->id);
        $this->react($m, '👍', 'incoming', $members[2]->id);
        $this->received($conv, $this->ago(20), 'Alguém sabe se a tabela de atacado mudou?', true, ['contact_id' => $members[2]->id]);

        foreach ([['Corrida de Rua SP', '120363049999000001@g.us', 'avatar-grupo-corrida.png', 8], ['Promoções Relâmpago', '120363049999000002@g.us', null, 3]] as [$name, $jid, $photo, $days]) {
            $removed = $this->contact($this->conn['apiway'], $jid, $name, array_filter(['is_group' => true, 'photo' => $photo, 'group_removed_at' => $this->now->copy()->subDays($days)]));
            $old = $this->conversation($this->conn['apiway'], $removed, ['status' => 'pending', 'type' => 'group']);
            $this->received($old, $this->now->copy()->subDays($days + 1), 'Treino no Ibirapuera sábado às 7h!', false, ['contact_id' => $members[0]->id]);
        }
    }

    private function threadsAttention(): void
    {
        $marina = $this->users['marina'];
        $ricardo = $this->conversation($this->conn['wa'], $this->people['ricardo'], ['status' => 'active', 'user_id' => $marina->id]);
        $this->received($ricardo, $this->ago(1560), 'Segue a foto da nota fiscal', false, ['message_type' => 'image', 'attachment_status' => 'expired']);
        $this->received($ricardo, $this->ago(1558), 'E o comprovante', false, ['message_type' => 'document', 'attachment_status' => 'failed']);
        $this->received($ricardo, $this->ago(1556), '', false, ['message_type' => 'unsupported', 'body' => null]);
        $this->sent($ricardo, $this->ago(1540), 'Recebi, Ricardo! Vou conferir com o financeiro.', ['sent_by_user_id' => $marina->id]);
        $this->received($ricardo, $this->ago(1500), 'Obrigado, aguardo o retorno');
        $this->received($ricardo, $this->ago(1499), 'Carregando…', false, ['message_type' => 'image', 'body' => 'Mais uma foto', 'attachment_status' => 'pending']);
    }

    private function threadsResolvedRecently(): void
    {
        $marina = $this->users['marina'];
        $juliana = $this->users['juliana'];

        $vanessa = $this->conversation($this->conn['wa'], $this->people['vanessa'], ['status' => 'active', 'user_id' => $marina->id]);
        $this->received($vanessa, $this->ago(240), 'Quero cancelar a compra que fiz ontem');
        $this->sent($vanessa, $this->ago(235), 'Cancelamento feito, Vanessa. O estorno aparece em até 2 faturas.', ['sent_by_user_id' => $marina->id]);
        $this->received($vanessa, $this->ago(230), 'Obrigada!');
        $this->note($vanessa, $this->ago(125), 'conversation_status_changed_by', ['by' => $marina->name, 'from_status' => 'active', 'to_status' => 'resolved'], 'Status changed.');
        $this->resolveAt($vanessa, $this->ago(125), $marina->id);

        $diego = $this->conversation($this->conn['ig'], $this->people['diego'], ['status' => 'active', 'user_id' => $juliana->id]);
        $this->received($diego, $this->brt(1, 10, 5), 'Vocês patrocinam atletas?');
        $this->sent($diego, $this->brt(1, 10, 20), 'Temos um programa de embaixadores! Envie seu portfólio para parcerias@lojaaurora.example', ['sent_by_user_id' => $juliana->id]);
        $this->resolveAt($diego, $this->brt(1, 11, 0), $juliana->id);
    }
}
