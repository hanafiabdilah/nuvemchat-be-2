<?php

namespace App\Enums\Integration;

/**
 * What an external app is *for*, which is how the Integrations page groups its
 * cards and how a flow node picks which accounts it may point at.
 *
 * The node is the reason this is a type rather than a label: a payment node
 * wired to a pixel account would save, and then fail at the one moment a
 * customer is waiting for a Pix code.
 */
enum IntegrationCategory: string
{
    case Payment = 'payment';
    case Pixel = 'pixel';

    /** English, translated by the dashboard like every other label. */
    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payments',
            self::Pixel => 'Pixels & analytics',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Payment => 'Charge customers inside a flow and branch on whether they paid.',
            self::Pixel => 'Report conversions from your conversations to your ad and analytics accounts.',
        };
    }
}
