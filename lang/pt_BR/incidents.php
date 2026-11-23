<?php

return [
    'errors' => [
        'over' => 'Este incidente já terminou e não pode mais ser alterado.',
        'not_active' => 'Este incidente não está ativo.',
        'not_scheduled' => 'Só é possível cancelar um incidente agendado. Para encerrar um incidente ativo, resolva-o.',
        'cannot_send' => 'Este aviso não pode ser enviado manualmente.',
        'overlap' => 'O incidente ":title" já cobre :connections nesse período. Encerre-o ou escolha outras conexões.',
        'keywords_required' => 'Adicione pelo menos uma palavra-chave, ou escolha "Todas as mensagens".',
        'keyword_too_short' => 'A palavra-chave ":keyword" é curta demais. Use pelo menos 3 letras.',
        'connection_unknown' => 'Uma das conexões escolhidas não existe ou você não tem acesso a ela.',
        'connection_email' => 'Incidentes não se aplicam a conexões de e-mail.',
        'min_duration' => 'Um incidente precisa durar pelo menos 15 minutos.',
        'max_duration' => 'Um incidente pode durar no máximo 72 horas.',
        'ends_in_past' => 'O fim do incidente precisa estar no futuro.',
        'bad_time' => 'Data ou hora inválida.',
        'resolution_required' => 'Escreva a mensagem de encerramento, ou desmarque o envio.',
        'ai_agent_required' => 'Escolha um agente de IA ativo desta conta para escrever o aviso.',
        'ai_brief_required' => 'Escreva o que a IA deve contar aos clientes sobre o incidente.',
        'template_limit' => 'Esta conta já tem o número máximo de modelos de incidente. Exclua um que não é mais usado.',
    ],
];
