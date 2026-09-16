<?php

namespace App\Enums\Notification;

use Illuminate\Support\Facades\Lang;

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

    /**
     * Rented gallery storage renews soon and the balance will not cover it.
     *
     * Repeated daily inside the window, like its two counterparts above, and
     * for the same reason: the only remedy is a person noticing. What follows
     * it is milder than a cancelled number — no file is ever deleted — so the
     * message says exactly that, rather than borrowing an urgency it does not
     * have and teaching people to discount the ones that do.
     */
    case GalleryStorageRenewalNoCredit = 'gallery_storage_renewal_no_credit';

    /** Rented gallery storage ended for want of balance. Files are untouched. */
    case GalleryStorageCancelledNoCredit = 'gallery_storage_cancelled_no_credit';

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
            self::GalleryStorageRenewalNoCredit => 'Gallery storage renewal blocked by balance',
            self::GalleryStorageCancelledNoCredit => 'Gallery storage ended (no balance)',
        };
    }

    /**
     * Default message template, in the language the recipient reads.
     *
     * The wording lives in `lang/{locale}/notifications.php` keyed by this
     * enum's own value, so adding a language is a file and adding an event is a
     * case here. It used to be a match arm per event, which was right while the
     * platform sold in one country and became the reason an Indonesian customer
     * received their signup code in Portuguese.
     *
     * ⚠️ Falls back explicitly rather than trusting the translator: a missing
     * key makes Laravel return the key itself, and "notifications.whatsapp_otp"
     * is a string this product would happily have sent to a customer.
     */
    public function defaultTemplate(?string $locale = null): string
    {
        $key = 'notifications.'.$this->value;
        $fallback = config('markets.default_locale');
        $locale = $locale ?: $fallback;

        // ⚠️ Two checks, and the first is the one that is easy to miss.
        // Lang::has() alone answers "can the translator produce something", and
        // for an unknown locale it answers yes — Laravel walks to
        // app.fallback_locale ('en') by itself. So a typo in a market row would
        // not land here, it would quietly switch that country's customers to
        // English. Asking whether we offer the language at all comes first;
        // whether the file carries this key comes second.
        $offered = array_key_exists($locale, config('markets.locales'));

        return (string) __($key, [], $offered && Lang::has($key, $locale) ? $locale : $fallback);
    }

    /**
     * Every language this event is written in, keyed by locale.
     *
     * For the Back Office editor, which has to show an admin what each language
     * currently says before they override it — a per-locale override written
     * against an unseen default is a guess.
     *
     * @return array<string, string>
     */
    public function defaultTemplates(): array
    {
        $out = [];

        foreach (array_keys(config('markets.locales')) as $locale) {
            $out[$locale] = $this->defaultTemplate($locale);
        }

        return $out;
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
            self::GalleryStorageRenewalNoCredit => ['name', 'gb', 'due_date', 'amount'],
            self::GalleryStorageCancelledNoCredit => ['name', 'gb'],
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
                // Kept alongside the per-locale map so a Back Office build that
                // predates languages still renders something real rather than
                // an empty editor.
                'default_template' => $t->defaultTemplate(),
                'default_templates' => $t->defaultTemplates(),
                'placeholders' => $t->placeholders(),
                'required' => $t->isRequired(),
            ],
            self::cases(),
        );
    }
}
