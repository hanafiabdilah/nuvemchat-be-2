<?php

/**
 * Push notifications to the mobile app, in Portuguese.
 *
 * ⚠️ The message itself is never in a push (product decision: nothing a
 * customer wrote appears on a lock screen). Title = who, body = what happened.
 * Placeholders are `{{name}}`, same convention as notifications.php.
 */
return [
    'a_contact' => 'Um contato',
    'message_received' => 'Nova mensagem',
    'conversation_handoff' => 'A IA transferiu esta conversa para um atendente',
    'conversation_transferred' => '{{agent}} transferiu esta conversa para você',
    'conversation_taken_over' => '{{agent}} assumiu esta conversa',
    'crm_assigned' => '{{agent}} atribuiu isto a você',
    'crm_task_due' => 'Tarefa com prazo {{when}}',
    'crm_automation' => '{{message}}',
    'test_title' => 'Pingly',
    'test_body' => 'As notificações estão funcionando neste aparelho.',
    // Voice calls (WhatsApp Cloud API): the ring, and the line that
    // replaces it once somebody answered or it is over.
    'incoming_call' => 'Ligação do WhatsApp',
    'call_answered_by' => '{{agent}} atendeu esta ligação',
    'call_missed' => 'Ligação perdida',
    'call_ended' => 'A ligação terminou',
];
