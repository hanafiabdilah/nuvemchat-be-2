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
