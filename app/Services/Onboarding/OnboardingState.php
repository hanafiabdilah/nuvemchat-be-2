<?php

namespace App\Services\Onboarding;

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Numbers\VirtualNumberStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\VirtualNumber;
use App\Services\Billing\SubscriptionGate;

/**
 * Whether a workspace still has to go through the first-run guide.
 *
 * A new workspace chooses how it starts — a plan for its channels, or just a
 * rented number — before it reaches the dashboard. It is done with the guide
 * once any of these is true, and every one of them is read from what the
 * workspace actually did rather than from a flag set along the way:
 *
 *  - it has access right now (a usable subscription, a manual grant, or a live
 *    API Way instance — the same answer EnsureSubscriptionActive uses);
 *  - it has ever paid for a plan (a lapsed plan is a billing problem, not a
 *    workspace that has never chosen how to start);
 *  - it has ever rented a number (numbers need no plan at all);
 *  - its owner chose to skip the guide.
 *
 * Read on every GET /user, so each check is one indexed query.
 */
class OnboardingState
{
    public function __construct(
        protected SubscriptionGate $gate,
    ) {}

    public function required(Tenant $tenant): bool
    {
        return $this->completedBy($tenant) === null;
    }

    /**
     * What finished it — 'skipped', 'plan' or 'number' — or null while it is
     * still pending. Cheapest check first.
     */
    public function completedBy(Tenant $tenant): ?string
    {
        if ($tenant->onboarding_skipped_at !== null) {
            return 'skipped';
        }

        if ($this->gate->usable($tenant) || $this->hasPaidForAPlan($tenant)) {
            return 'plan';
        }

        if ($this->hasRentedANumber($tenant)) {
            return 'number';
        }

        return null;
    }

    public function skip(Tenant $tenant): void
    {
        if ($tenant->onboarding_skipped_at === null) {
            $tenant->forceFill(['onboarding_skipped_at' => now()])->save();
        }
    }

    protected function hasPaidForAPlan(Tenant $tenant): bool
    {
        return Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', InvoicePurpose::Subscription->value)
            ->where('status', InvoiceStatus::Paid->value)
            ->exists();
    }

    /**
     * A rental the balance actually paid for. `failed` never charged anything
     * (or was reversed), so it does not count as having bought a number.
     */
    protected function hasRentedANumber(Tenant $tenant): bool
    {
        return VirtualNumber::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', VirtualNumberStatus::Failed->value)
            ->exists();
    }
}
