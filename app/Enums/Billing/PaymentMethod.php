<?php

namespace App\Enums\Billing;

enum PaymentMethod: string
{
    case Card = 'card';
    case Pix = 'pix';
    case Manual = 'manual';
}
