<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Models\Customer;
use App\Models\User;

/**
 * The relationship status moving forward on its own: a customer with a lead or
 * deal open becomes a Prospect, one with a won deal becomes a Customer. Only
 * ever forward — nothing here moves someone back, and an Inactive customer
 * someone chose to mark stays as marked until they buy.
 */
class CustomerLifecycle
{
    private const RANK = [
        'new' => 0,
        'inactive' => 0,
        'prospect' => 1,
        'customer' => 2,
    ];

    public static function promote(Customer $customer, CustomerStatus $to, ?User $actor): void
    {
        $from = $customer->status?->value ?? CustomerStatus::New->value;

        if ((self::RANK[$to->value] ?? 0) <= (self::RANK[$from] ?? 0)) {
            return;
        }

        // An Inactive customer is only moved by a sale, never by a new lead.
        if ($from === CustomerStatus::Inactive->value && $to !== CustomerStatus::Customer) {
            return;
        }

        $customer->forceFill(['status' => $to])->save();

        CrmTimeline::record($customer, CrmTimeline::UPDATED, $actor, [
            'changes' => ['status' => ['from' => $from, 'to' => $to->value]],
            'automatic' => true,
        ]);
    }
}
