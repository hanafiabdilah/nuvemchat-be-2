<?php

/*
 * Words that go inside the WhatsApp alerts (App\Services\Notification\WhatsappAlerts):
 * the reasons and periods each template's placeholders are filled with, in the
 * recipient's language. The sentences themselves are the notification templates.
 */

return [
    'connection_reasons' => [
        'logged_out' => 'the number was unlinked from the phone or WhatsApp ended the session',
        'offline' => 'the number has been offline for more than 5 minutes',
        'token_revoked' => 'the access granted by Meta expired or was revoked',
        'app_removed' => 'the app was removed in the Facebook settings',
        'partner_removed' => 'the number was disconnected from the WhatsApp Business app',
    ],
    'receipt_reasons' => [
        'not_configured' => 'no AI agent is set on the flow step',
        'unreadable' => 'the file could not be read',
        'not_a_receipt' => 'the file is not a payment receipt',
        'not_completed' => 'the payment on it is not completed',
        'amount_unreadable' => 'the amount could not be read',
        'amount_mismatch' => 'the amount is lower than expected',
        'recipient_mismatch' => 'it was paid to someone else',
        'duplicate' => 'this receipt was already used',
        'ai_unavailable' => 'the AI could not check it',
        'unknown' => 'it could not be confirmed',
    ],
    'unnamed_flow' => 'unnamed',
    'unknown_reason' => 'not given',
    'period_hour' => 'between :from and :to',
    'period_day' => 'yesterday (:date)',
    'details_connection' => '• :connection: :count',
    'details_sales' => 'Sales: :count (:amount)',
];
