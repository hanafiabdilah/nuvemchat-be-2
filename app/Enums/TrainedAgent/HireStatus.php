<?php

namespace App\Enums\TrainedAgent;

/**
 * Lifecycle of one hire.
 *
 * `provisioning` exists because forking a blueprint is a dozen HTTP calls to
 * the AI Hub (agent, profile, and every knowledge / skill / example row), which
 * is far too much to hold a checkout request — or a MercadoPago webhook — open
 * for. The hire row is created first and the copy happens in a queued job.
 */
enum HireStatus: string
{
    /** Paid hire awaiting a Pix payment. Nothing has been created on the hub. */
    case PendingPayment = 'pending_payment';
    /** Paid for (or free) and queued: the fork job has not finished yet. */
    case Provisioning = 'provisioning';
    /** The agent exists in the tenant's workspace. */
    case Active = 'active';
    /** The fork job gave up. Money may have been taken — see `meta.needs_refund`. */
    case Failed = 'failed';
    /** Checkout abandoned before payment. */
    case Cancelled = 'cancelled';

    /**
     * Whether this row occupies one of the plan's included slots.
     *
     * A failed fork deliberately does NOT: the tenant got nothing, so holding
     * their allowance hostage for it would turn our outage into their lost
     * entitlement. Retrying re-uses the same row and puts it back in flight.
     */
    public function consumesAllowance(): bool
    {
        return $this === self::Provisioning || $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Provisioning => 'Setting up',
            self::Active => 'Active',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
