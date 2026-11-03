<?php

namespace App\Enums\Reservation;

/**
 * Why a request waits for the team instead of being confirmed by itself —
 * shown on the reservation so the operator knows what to look at.
 *
 *  - `needs_confirmation`  — the service is set to "the team confirms".
 *  - `ai_unsure`           — the AI flagged it (a special request, something it
 *                            could not settle), with its reason in `review_note`.
 *  - `party_too_large`     — more people than the service takes per booking.
 *  - `no_schedule`         — no service is set up: the request was taken as
 *                            the customer said it.
 *  - `payment_unavailable` — the service asks for money and nothing could
 *                            charge it (the node has no payment account, or
 *                            the gateway refused): the team collects it.
 */
enum ReviewReason: string
{
    case NeedsConfirmation = 'needs_confirmation';
    case AiUnsure = 'ai_unsure';
    case PartyTooLarge = 'party_too_large';
    case NoSchedule = 'no_schedule';
    case PaymentUnavailable = 'payment_unavailable';
}
