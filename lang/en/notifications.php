<?php

/**
 * The platform's own WhatsApp messages, in English.
 *
 * See lang/pt_BR/notifications.php for the rules that govern this file — in
 * particular that placeholders are `{{name}}` and never Laravel's `:name`.
 *
 * English is offered because `config('markets.locales')` offers it, so a market
 * can be opened defaulting to it. No market does today; these exist so that
 * opening one is a row in the Back Office rather than a deploy.
 */

return [

    'whatsapp_otp' => '🔐 Your Pingly verification code is *{{code}}*. It expires in {{ttl}} minutes. Do not share this code.',

    'password_reset_otp' => '🔑 Hi {{name}}, your code to reset your Chat Pingly password is *{{code}}*. It expires in {{ttl}} minutes. If you did not ask for it, ignore this message and do not share the code.',

    'password_changed' => '✅ Hi {{name}}, your Chat Pingly password was changed on {{datetime}}. If this was not you, contact support immediately.',

    'welcome_registration' => 'Hi {{name}}! 👋 Your Chat Pingly account is ready. Choose a plan to get started.',

    'subscription_activated' => 'Congratulations {{name}}! 🎉 Your {{plan}} subscription is active. Enjoy!',

    'subscription_due' => 'Hi {{name}}, your {{plan}} subscription is due on {{due_date}}. Amount: {{amount}}.',

    'subscription_past_due' => 'Hi {{name}}, we have not received payment for your {{plan}} subscription. Settle it to avoid suspension.',

    'subscription_suspended' => 'Hi {{name}}, your {{plan}} subscription was suspended for non-payment. You can reactivate it whenever you like.',

    'apiway_purchase_activated' => 'Hi {{name}}! 🎉 Your {{quantity}} API Way instance(s) are active. Open the dashboard to pair your WhatsApp.',

    'apiway_renewal_due' => 'Hi {{name}}, your API Way subscription is due on {{due_date}}. Amount: {{amount}}. Careful: after the due date the instance is switched off permanently.',

    'apiway_expired' => 'Hi {{name}}, your API Way subscription expired and the instance(s) were switched off permanently. Rent a new instance to carry on.',

    'apiway_provision_failed' => 'Hi {{name}}, we could not activate your API Way instance. Our team has been alerted and will get in touch.',

    'apiway_provision_refunded' => 'Hi {{name}}, we could not activate your API Way instance and {{amount}} is already back in your balance. You can try again from the dashboard.',

    'apiway_renewal_no_credit' => 'Hi {{name}}, your API Way subscription is due on {{due_date}} and your balance will not cover the renewal ({{amount}}). Top up before the due date: after it the instance is switched off permanently and cannot be recovered.',

    'credit_low_balance' => 'Hi {{name}}, your balance is running out: {{amount}} left. Top up to keep your AI replies and your instances running.',

    'virtual_number_renewal_no_credit' => 'Hi {{name}}, your number {{msisdn}} renews on {{due_date}} and your balance will not cover the renewal ({{amount}}). Top up before the due date: without balance the number is cancelled and cannot be recovered.',

    'virtual_number_cancelled_no_credit' => 'Hi {{name}}, your number {{msisdn}} was cancelled because there was no balance for the renewal. You can rent a new number from the dashboard whenever you like.',

    'virtual_number_refunded' => 'Hi {{name}}, we could not activate the virtual number and {{amount}} is already back in your balance. You can try again from the dashboard.',

    'gallery_storage_renewal_no_credit' => 'Hi {{name}}, your extra gallery storage ({{gb}} GB) renews on {{due_date}} and your balance will not cover the renewal ({{amount}}). Top up before the due date. Your files will not be deleted, but new uploads to the gallery are blocked while you are over the limit.',

    'gallery_storage_cancelled_no_credit' => 'Hi {{name}}, your extra gallery storage ({{gb}} GB) ended for want of balance. No file was deleted — they are all still available to send. To upload new files again, top up and rent the space once more.',

];
