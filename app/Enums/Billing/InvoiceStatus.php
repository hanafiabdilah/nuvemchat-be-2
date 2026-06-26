<?php

namespace App\Enums\Billing;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
