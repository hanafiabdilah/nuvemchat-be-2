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
    'test_title' => 'Pingly',
    'test_body' => 'As notificações estão funcionando neste aparelho.',
];
