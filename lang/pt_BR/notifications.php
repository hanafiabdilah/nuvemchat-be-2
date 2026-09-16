<?php

/**
 * The platform's own WhatsApp messages, in Portuguese.
 *
 * Keys are `NotificationType` case values — the enum is the catalog, this file
 * is only the wording, and a key missing here falls back to the platform's
 * default language rather than reaching a customer as "notifications.xyz".
 *
 * ⚠️ Placeholders are `{{name}}`, NOT Laravel's `:name`. They are interpolated
 * by NotificationService::render() after the translator has run, because the
 * same strings are editable by an admin in the Back Office and `{{ }}` is the
 * convention that editor has always shown. Never write `:name` here — the
 * translator would replace it and the admin's copy of the string would not
 * match what is actually sent.
 *
 * ⚠️ These are the exact strings this product has been sending since before it
 * had a second language. They are reproduced byte for byte on purpose: moving
 * them into a file is not an occasion to reword what Brazilian customers
 * already receive.
 */

return [

    'whatsapp_otp' => '🔐 Seu código de verificação Pingly é *{{code}}*. Ele expira em {{ttl}} minutos. Não compartilhe este código.',

    'password_reset_otp' => '🔑 Olá {{name}}, seu código para redefinir a senha da Chat Pingly é *{{code}}*. Ele expira em {{ttl}} minutos. Se não foi você que pediu, ignore esta mensagem e não compartilhe o código.',

    'password_changed' => '✅ Olá {{name}}, a senha da sua conta Chat Pingly foi alterada em {{datetime}}. Se não foi você, entre em contato com o suporte imediatamente.',

    'welcome_registration' => 'Olá {{name}}! 👋 Sua conta na Chat Pingly foi criada com sucesso. Escolha um plano para começar.',

    'subscription_activated' => 'Parabéns {{name}}! 🎉 Sua assinatura do plano {{plan}} está ativa. Bom trabalho!',

    'subscription_due' => 'Olá {{name}}, sua assinatura {{plan}} vence em {{due_date}}. Valor: {{amount}}.',

    'subscription_past_due' => 'Olá {{name}}, não identificamos o pagamento da sua assinatura {{plan}}. Regularize para evitar a suspensão.',

    'subscription_suspended' => 'Olá {{name}}, sua assinatura {{plan}} foi suspensa por falta de pagamento. Reative quando quiser.',

    'apiway_purchase_activated' => 'Olá {{name}}! 🎉 Sua(s) {{quantity}} instância(s) API Way já está(ão) ativa(s). Acesse o painel para parear seu WhatsApp.',

    'apiway_renewal_due' => 'Olá {{name}}, sua assinatura API Way vence em {{due_date}}. Valor: {{amount}}. Atenção: após o vencimento a instância é desativada permanentemente.',

    'apiway_expired' => 'Olá {{name}}, sua assinatura API Way expirou e a(s) instância(s) foi(ram) desativada(s) permanentemente. Contrate uma nova instância para continuar.',

    'apiway_provision_failed' => 'Olá {{name}}, não conseguimos ativar sua instância API Way. Nossa equipe já foi acionada e entrará em contato.',

    'apiway_provision_refunded' => 'Olá {{name}}, não conseguimos ativar sua instância API Way e devolvemos {{amount}} ao seu saldo. Você pode tentar novamente pelo painel.',

    'apiway_renewal_no_credit' => 'Olá {{name}}, sua assinatura API Way vence em {{due_date}} e seu saldo não cobre a renovação ({{amount}}). Recarregue antes do vencimento: depois dele a instância é desativada permanentemente e não há como recuperá-la.',

    'credit_low_balance' => 'Olá {{name}}, seu saldo está acabando: restam {{amount}}. Recarregue para o seu atendimento com IA e suas instâncias continuarem funcionando.',

    'virtual_number_renewal_no_credit' => 'Olá {{name}}, seu número {{msisdn}} renova em {{due_date}} e seu saldo não cobre a renovação ({{amount}}). Recarregue antes do vencimento: sem saldo o número é cancelado e não pode ser recuperado.',

    'virtual_number_cancelled_no_credit' => 'Olá {{name}}, seu número {{msisdn}} foi cancelado porque não havia saldo para a renovação. Contrate um novo número pelo painel quando quiser.',

    'virtual_number_refunded' => 'Olá {{name}}, não conseguimos ativar o número virtual e devolvemos {{amount}} ao seu saldo. Você pode tentar novamente pelo painel.',

    'gallery_storage_renewal_no_credit' => 'Olá {{name}}, seu armazenamento extra da galeria ({{gb}} GB) renova em {{due_date}} e seu saldo não cobre a renovação ({{amount}}). Recarregue antes do vencimento. Seus arquivos não serão apagados, mas novos envios para a galeria ficam bloqueados enquanto o espaço estiver acima do limite.',

    'gallery_storage_cancelled_no_credit' => 'Olá {{name}}, seu armazenamento extra da galeria ({{gb}} GB) foi encerrado por falta de saldo. Nenhum arquivo foi apagado — eles continuam disponíveis para envio. Para voltar a subir arquivos novos, recarregue e contrate o espaço novamente.',

];
