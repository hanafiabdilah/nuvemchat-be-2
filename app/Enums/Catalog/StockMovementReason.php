<?php

namespace App\Enums\Catalog;

/** Why a variant's stock changed. Every change has one — see StockService. */
enum StockMovementReason: string
{
    /** Somebody typed a new number on the Products page. */
    case Manual = 'manual';

    /** A spreadsheet import set it. */
    case Import = 'import';

    /** The customer paid; the units left the shelf. */
    case OrderPaid = 'order_paid';
}
