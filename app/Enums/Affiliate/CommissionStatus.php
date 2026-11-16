<?php

namespace App\Enums\Affiliate;

/**
 * Where a commission stands. "Holding" and "available" are not stored: both
 * are `pending`, told apart by `available_at` — a date that passes needs no job
 * to move it.
 */
enum CommissionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    /** The charge it came from was refunded before it was paid out. */
    case Reversed = 'reversed';
}
