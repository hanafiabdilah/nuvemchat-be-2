<?php

namespace App\Enums\Notification;

/**
 * Catalog of platform-level transactional notifications (sent to tenants/owners
 * over WhatsApp). This is the single source of truth for which lifecycle events
 * can trigger a notification. Add a case here to introduce a new notification.
 *
 * Message bodies are DYNAMIC: the strings below are only defaults — super-admin can
 * override each per-event template in Back Office → Integrations → Notifications, and
 * the override is interpolated with the placeholders listed in placeholders().
 */
enum NotificationType: string
{
    case WhatsappOtp = 'whatsapp_otp';
    case PasswordResetOtp = 'password_reset_otp';
    case PasswordChanged = 'password_changed';
    case WelcomeRegistration = 'welcome_registration';
    case SubscriptionActivated = 'subscription_activated';
    case SubscriptionDue = 'subscription_due';
    case SubscriptionPastDue = 'subscription_past_due';
    case SubscriptionSuspended = 'subscription_suspended';
    case ApiwayPurchaseActivated = 'apiway_purchase_activated';
    case ApiwayRenewalDue = 'apiway_renewal_due';
    case ApiwayExpired = 'apiway_expired';
    case ApiwayProvisionFailed = 'apiway_provision_failed';
    /**
     * Provisioning failed on a purchase paid from the prepaid balance, and the
     * charge has already been given back.
     *
     * Its own case rather than a parameter on the one above, because the two
     * say opposite things about what the customer has to do next: one asks
     * them to wait for a call, this one tells them the money is already there
     * and they can try again.
     */
    case ApiwayProvisionRefunded = 'apiway_provision_refunded';
    /** A renewal is due and the balance will not cover it. */
    case ApiwayRenewalNoCredit = 'apiway_renewal_no_credit';
    /**
     * The prepaid balance has fallen below the warning threshold.
     *
     * Sent once per drop and cleared by the next top-up, so a workspace sitting
     * just under the line is not messaged on every run it pays for.
     */
    case CreditLowBalance = 'credit_low_balance';

    /**
     * A rented number is about to renew and the balance will not cover it.
     *
     * Repeated daily inside the window, like its API Way counterpart: the only
     * remedy is a person noticing and topping up, and the number is deleted at
     * the deadline.
     */
    case VirtualNumberRenewalNoCredit = 'virtual_number_renewal_no_credit';

    /** A rented number was cancelled because the renewal could not be paid. */
    case VirtualNumberCancelledNoCredit = 'virtual_number_cancelled_no_credit';

    /** A number was charged for and never delivered; the money is already back. */
    case VirtualNumberRefunded = 'virtual_number_refunded';

    /** Human-readable label for the Back Office configuration UI. */
    public function label(): string
    {
        return match ($this) {
            self::WhatsappOtp => 'WhatsApp verification code (OTP)',
            self::PasswordResetOtp => 'Password reset code (OTP)',
            self::PasswordChanged => 'Password changed confirmation',
            self::WelcomeRegistration => 'Welcome (registration)',
            self::SubscriptionActivated => 'Subscription activated',
            self::SubscriptionDue => 'Subscription due date',
            self::SubscriptionPastDue => 'Payment overdue',
            self::SubscriptionSuspended => 'Subscription suspended',
            self::ApiwayPurchaseActivated => 'API Way instance activated',
            self::ApiwayRenewalDue => 'API Way renewal due',
            self::ApiwayExpired => 'API Way subscription expired',
            self::ApiwayProvisionFailed => 'API Way provisioning failed',
            self::ApiwayProvisionRefunded => 'API Way provisioning failed (credit returned)',
            self::ApiwayRenewalNoCredit => 'API Way renewal blocked by balance',
            self::CreditLowBalance => 'Prepaid balance running low',
            self::VirtualNumberRenewalNoCredit => 'Virtual number renewal blocked by balance',
            self::VirtualNumberCancelledNoCredit => 'Virtual number cancelled (no balance)',
            self::VirtualNumberRefunded => 'Virtual number not delivered (credit returned)',
        };
    }

    /**
     * Default message template. Placeholders are interpolated by
     * NotificationService::render() / OtpService using the {{key}} convention.
     */
    public function defaultTemplate(): string
    {
        return match ($this) {
            self::WhatsappOtp => "🔐 Seu código de verificação Pingly é *{{code}}*. Ele expira em {{ttl}} minutos. Não compartilhe este código.",
            self::PasswordResetOtp => "🔑 Olá {{name}}, seu código para redefinir a senha da Chat Pingly é *{{code}}*. Ele expira em {{ttl}} minutos. Se não foi você que pediu, ignore esta mensagem e não compartilhe o código.",
            self::PasswordChanged => "✅ Olá {{name}}, a senha da sua conta Chat Pingly foi alterada em {{datetime}}. Se não foi você, entre em contato com o suporte imediatamente.",
            self::WelcomeRegistration => "Olá {{name}}! 👋 Sua conta na Chat Pingly foi criada com sucesso. Escolha um plano para começar.",
            self::SubscriptionActivated => "Parabéns {{name}}! 🎉 Sua assinatura do plano {{plan}} está ativa. Bom trabalho!",
            self::SubscriptionDue => "Olá {{name}}, sua assinatura {{plan}} vence em {{due_date}}. Valor: {{amount}}.",
            self::SubscriptionPastDue => "Olá {{name}}, não identificamos o pagamento da sua assinatura {{plan}}. Regularize para evitar a suspensão.",
            self::SubscriptionSuspended => "Olá {{name}}, sua assinatura {{plan}} foi suspensa por falta de pagamento. Reative quando quiser.",
            self::ApiwayPurchaseActivated => "Olá {{name}}! 🎉 Sua(s) {{quantity}} instância(s) API Way já está(ão) ativa(s). Acesse o painel para parear seu WhatsApp.",
            self::ApiwayRenewalDue => "Olá {{name}}, sua assinatura API Way vence em {{due_date}}. Valor: {{amount}}. Atenção: após o vencimento a instância é desativada permanentemente.",
            self::ApiwayExpired => "Olá {{name}}, sua assinatura API Way expirou e a(s) instância(s) foi(ram) desativada(s) permanentemente. Contrate uma nova instância para continuar.",
            self::ApiwayProvisionFailed => "Olá {{name}}, não conseguimos ativar sua instância API Way. Nossa equipe já foi acionada e entrará em contato.",
            self::ApiwayProvisionRefunded => "Olá {{name}}, não conseguimos ativar sua instância API Way e devolvemos {{amount}} ao seu saldo. Você pode tentar novamente pelo painel.",
            self::ApiwayRenewalNoCredit => "Olá {{name}}, sua assinatura API Way vence em {{due_date}} e seu saldo não cobre a renovação ({{amount}}). Recarregue antes do vencimento: depois dele a instância é desativada permanentemente e não há como recuperá-la.",
            self::CreditLowBalance => "Olá {{name}}, seu saldo está acabando: restam {{amount}}. Recarregue para o seu atendimento com IA e suas instâncias continuarem funcionando.",
            self::VirtualNumberRenewalNoCredit => "Olá {{name}}, seu número {{msisdn}} renova em {{due_date}} e seu saldo não cobre a renovação ({{amount}}). Recarregue antes do vencimento: sem saldo o número é cancelado e não pode ser recuperado.",
            self::VirtualNumberCancelledNoCredit => "Olá {{name}}, seu número {{msisdn}} foi cancelado porque não havia saldo para a renovação. Contrate um novo número pelo painel quando quiser.",
            self::VirtualNumberRefunded => "Olá {{name}}, não conseguimos ativar o número virtual e devolvemos {{amount}} ao seu saldo. Você pode tentar novamente pelo painel.",
        };
    }

    /**
     * Placeholders available to this event's template (without the {{ }} braces),
     * so the UI can hint which variables are interpolatable.
     *
     * @return array<int, string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::WhatsappOtp => ['code', 'ttl'],
            self::PasswordResetOtp => ['name', 'code', 'ttl'],
            self::PasswordChanged => ['name', 'datetime'],
            self::WelcomeRegistration => ['name'],
            self::SubscriptionActivated => ['name', 'plan'],
            self::SubscriptionDue => ['name', 'plan', 'due_date', 'amount'],
            self::SubscriptionPastDue => ['name', 'plan'],
            self::SubscriptionSuspended => ['name', 'plan'],
            self::ApiwayPurchaseActivated => ['name', 'quantity'],
            self::ApiwayRenewalDue => ['name', 'due_date', 'amount', 'quantity'],
            self::ApiwayExpired => ['name', 'quantity'],
            self::ApiwayProvisionFailed => ['name'],
            self::ApiwayProvisionRefunded => ['name', 'amount'],
            self::ApiwayRenewalNoCredit => ['name', 'due_date', 'amount'],
            self::CreditLowBalance => ['name', 'amount'],
            self::VirtualNumberRenewalNoCredit => ['name', 'msisdn', 'due_date', 'amount'],
            self::VirtualNumberCancelledNoCredit => ['name', 'msisdn'],
            self::VirtualNumberRefunded => ['name', 'amount'],
        };
    }

    /**
     * Required events are transactional and always sent regardless of the master
     * enable/per-event toggles (e.g. the OTPs — disabling them would break signup
     * and lock users out of password recovery). Their template is still editable.
     */
    public function isRequired(): bool
    {
        return in_array($this, [self::WhatsappOtp, self::PasswordResetOtp], true);
    }

    /**
     * Discriminator written to whatsapp_message_logs.type ("otp* | notification:<event>").
     * The signup OTP keeps its bare 'otp' value: the column is indexed, already holds
     * production rows, and the Back Office log filter selects on it. Other OTPs share
     * that prefix so the same filter (a LIKE 'otp%') still catches them.
     */
    public function logType(): string
    {
        return match ($this) {
            self::WhatsappOtp => 'otp',
            self::PasswordResetOtp => 'otp:password_reset',
            default => 'notification:' . $this->value,
        };
    }

    /**
     * Catalog for the configuration UI.
     *
     * @return array<int, array{value: string, label: string, default_template: string, placeholders: array<int, string>, required: bool}>
     */
    public static function catalog(): array
    {
        return array_map(
            fn (self $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'default_template' => $t->defaultTemplate(),
                'placeholders' => $t->placeholders(),
                'required' => $t->isRequired(),
            ],
            self::cases(),
        );
    }
}
