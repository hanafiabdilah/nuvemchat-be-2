<?php

namespace App\Enums\Notification;

/**
 * Catalog of platform-level transactional notifications (sent to tenants/owners
 * over WhatsApp). This is the single source of truth for which lifecycle events
 * can trigger a notification. Add a case here to introduce a new notification.
 *
 * Blueprint: the templates below are defaults; nothing dispatches these yet.
 */
enum NotificationType: string
{
    case WelcomeRegistration = 'welcome_registration';
    case SubscriptionActivated = 'subscription_activated';
    case SubscriptionDue = 'subscription_due';
    case SubscriptionPastDue = 'subscription_past_due';
    case SubscriptionSuspended = 'subscription_suspended';

    /** Human-readable label for the Back Office configuration UI. */
    public function label(): string
    {
        return match ($this) {
            self::WelcomeRegistration => 'Welcome (registration)',
            self::SubscriptionActivated => 'Subscription activated',
            self::SubscriptionDue => 'Subscription due date',
            self::SubscriptionPastDue => 'Payment overdue',
            self::SubscriptionSuspended => 'Subscription suspended',
        };
    }

    /**
     * Default message template. Placeholders are interpolated by
     * NotificationService::render() using the {{key}} convention.
     */
    public function defaultTemplate(): string
    {
        return match ($this) {
            self::WelcomeRegistration => "Olá {{name}}! 👋 Sua conta na Chat Pingly foi criada com sucesso. Escolha um plano para começar.",
            self::SubscriptionActivated => "Parabéns {{name}}! 🎉 Sua assinatura do plano {{plan}} está ativa. Bom trabalho!",
            self::SubscriptionDue => "Olá {{name}}, sua assinatura {{plan}} vence em {{due_date}}. Valor: {{amount}}.",
            self::SubscriptionPastDue => "Olá {{name}}, não identificamos o pagamento da sua assinatura {{plan}}. Regularize para evitar a suspensão.",
            self::SubscriptionSuspended => "Olá {{name}}, sua assinatura {{plan}} foi suspensa por falta de pagamento. Reative quando quiser.",
        };
    }

    /**
     * Catalog for the configuration UI: [{ value, label }, ...].
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function catalog(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
