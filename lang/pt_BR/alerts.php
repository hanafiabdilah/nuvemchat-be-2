<?php

/*
 * Words that go inside the WhatsApp alerts (App\Services\Notification\WhatsappAlerts):
 * the reasons and periods each template's placeholders are filled with, in the
 * recipient's language. The sentences themselves are the notification templates.
 */

return [
    'connection_reasons' => [
        'logged_out' => 'o número foi desvinculado do celular ou o WhatsApp encerrou a sessão',
        'offline' => 'o número está fora do ar há mais de 5 minutos',
        'token_revoked' => 'o acesso concedido pela Meta expirou ou foi revogado',
        'app_removed' => 'o aplicativo foi removido nas configurações do Facebook',
        'partner_removed' => 'o número foi desconectado pelo app WhatsApp Business',
    ],
    'receipt_reasons' => [
        'not_configured' => 'nenhum agente de IA está configurado na etapa do fluxo',
        'unreadable' => 'o arquivo não pôde ser lido',
        'not_a_receipt' => 'o arquivo não é um comprovante de pagamento',
        'not_completed' => 'o pagamento não está concluído',
        'amount_unreadable' => 'não foi possível ler o valor',
        'amount_mismatch' => 'o valor é menor que o esperado',
        'recipient_mismatch' => 'o pagamento foi para outra pessoa',
        'duplicate' => 'este comprovante já foi usado',
        'ai_unavailable' => 'a IA não conseguiu verificar',
        'unknown' => 'não foi possível confirmar',
    ],
    'unnamed_flow' => 'sem nome',
    'unknown_reason' => 'não informado',
    'period_hour' => 'entre :from e :to',
    'period_day' => 'ontem (:date)',
    'details_connection' => '• :connection: :count',
    'details_sales' => 'Vendas: :count (:amount)',
];
