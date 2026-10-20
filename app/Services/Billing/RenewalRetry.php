<?php

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a refused renewal may be charged again, and when it must not be.
 *
 * `billing:charge-renewals` runs hourly and starts three days before the period
 * ends — that was always meant to give a temporary refusal ("no funds this
 * morning") several chances. It never did: the failed invoice kept the cycle's
 * order reference, the guard read any invoice with that reference as "already
 * billed", and the one attempt was the only attempt. The plan then lapsed with
 * nobody having tried again.
 *
 * The rule now, and why each half of it:
 *
 *  - **The same invoice, never a second one.** A cycle has one invoice — its
 *    reference is unique — and a retry reopens it with a fresh idempotency key.
 *    Two invoices for one cycle is how a customer gets charged twice.
 *  - **At most once a day, four times per cycle.** Hourly retries on a refused
 *    card are what the card networks fine merchants for, and a bank that said
 *    "no funds" at 09:00 says it again at 10:00. Three days before the end,
 *    two, one, and on the day; the grace period then tries once a day on its
 *    own invoice.
 *  - **Never when the issuer said not to.** `permanent` (lost, stolen, closed —
 *    the card is dropped) and `action_required` (the bank wants the cardholder:
 *    3-D Secure, or the card details confirmed) only change when a person acts,
 *    and the Billing page asks them to.
 *
 * A renewal invoice says it is one in `meta.renewal_attempts`; nothing else is
 * ever reopened here — not a first charge, not a top-up, not an invoice a person
 * tried to pay by hand.
 */
final class RenewalRetry
{
    public const MAX_ATTEMPTS = 4;

    /**
     * Not 24: the scheduler fires on the hour, and a charge that took a few
     * minutes would otherwise slide an hour later every day.
     */
    public const MIN_HOURS_BETWEEN = 20;

    /** Categories a gateway gives that only a person can change. */
    protected const NOT_RETRYABLE = ['permanent', 'action_required'];

    public static function isRenewal(Invoice $invoice): bool
    {
        return isset(($invoice->meta ?? [])['renewal_attempts']);
    }

    public static function attempts(Invoice $invoice): int
    {
        return max(1, (int) (($invoice->meta ?? [])['renewal_attempts'] ?? 1));
    }

    /**
     * @param  array<string, mixed>|null  $decline  {category, code, advice?, may_retry?}
     */
    public static function retryable(?array $decline): bool
    {
        if ($decline === null) {
            // A transport failure, a gateway that answered with an error: no
            // issuer refused the card, so trying again is the right answer.
            return true;
        }

        if (($decline['may_retry'] ?? null) === false) {
            return false;
        }

        return ! in_array($decline['category'] ?? null, self::NOT_RETRYABLE, true);
    }

    /** When the last attempt on this invoice failed. */
    public static function lastAttemptAt(Invoice $invoice): ?CarbonInterface
    {
        $at = ($invoice->meta ?? [])['failure']['at'] ?? null;

        return $at ? Carbon::parse($at) : $invoice->updated_at;
    }

    /**
     * Whether the scheduler should try a failed renewal again, now.
     *
     * Only an attempt the scheduler made itself counts: once the customer has
     * tried a card of their own on this cycle (stage `card_update`), the old
     * card is not charged behind their back.
     */
    public static function due(Invoice $invoice, ?CarbonInterface $now = null): bool
    {
        $next = self::nextAttemptAt($invoice);

        return $next !== null && $next->lessThanOrEqualTo($now ?? now());
    }

    /** When the scheduler will try this invoice again, or null when it will not. */
    public static function nextAttemptAt(Invoice $invoice): ?CarbonInterface
    {
        $failure = ($invoice->meta ?? [])['failure'] ?? [];

        if ($invoice->status !== InvoiceStatus::Failed
            || ! self::isRenewal($invoice)
            || ($failure['stage'] ?? null) !== 'card_renewal'
            || self::attempts($invoice) >= self::MAX_ATTEMPTS
            || ! self::retryable($failure['decline'] ?? null)) {
            return null;
        }

        return self::lastAttemptAt($invoice)?->copy()->addHours(self::MIN_HOURS_BETWEEN);
    }

    /**
     * The reference the gateway sees for an attempt. The first is the cycle's
     * own; later ones carry `-r{n}`, because a gateway that is exactly-once on
     * its reference (the payment service) would otherwise hand back the refusal
     * it already gave instead of trying the card. Our invoice keeps the cycle's
     * reference either way — matchInvoice() folds the suffix back.
     */
    public static function gatewayReference(string $reference, int $attempt): string
    {
        return $attempt > 1 ? "{$reference}-r{$attempt}" : $reference;
    }

    /** The cycle reference a gateway reference was sent for. */
    public static function cycleReference(string $gatewayReference): ?string
    {
        return preg_match('/^(pingly-sub-\d+-\d{4}-\d{2}-\d{2})-r\d+$/', $gatewayReference, $m) ? $m[1] : null;
    }
}
