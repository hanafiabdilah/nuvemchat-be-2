<?php

namespace App\Services\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\FlowPayment;
use App\Models\Reservation;

/**
 * What the AI is told when something happens to a reservation the customer is
 * waiting on — the team answered it, or its payment arrived or ran out — so it
 * can tell the customer in its own words
 * (FlowExecutor::announceReservationDecision).
 *
 * Written for the agent, in English, like every instruction we give it: the
 * model answers in the customer's language either way. It travels as the
 * run's `message.content`, which the hub's handoff detector scans, so it says
 * plainly that it is an update from the shop — never anything that reads like
 * the customer talking, and none of the words that detector reacts to — and
 * the next turn knows which reservation an "ok, 20:00 is fine" is about.
 */
final class ReservationAnnouncement
{
    public static function prompt(Reservation $reservation, string $action, ?FlowPayment $payment = null): string
    {
        $timezone = $reservation->timezone();
        $what = self::describe($reservation, $reservation->localStart($timezone)->format('l Y-m-d \a\t H:i'));
        $note = $reservation->decision_note ? ' Note from the shop: "'.str_replace('"', "'", $reservation->decision_note).'".' : '';

        $instruction = match ($action) {
            ReservationService::ACTION_CONFIRM => $reservation->status === ReservationStatus::AwaitingPayment
                ? "The shop ACCEPTED the reservation {$what}.{$note} To confirm it, the customer pays "
                    .($payment?->formattedAmount() ?? $reservation->formattedAmountDue())
                    .': the payment details were sent in the messages just above — do not repeat the code or the link. Tell them the reservation is confirmed as soon as it is paid'
                    .($payment?->expires_at ? ', and that the payment is valid for '.max(1, (int) round(now()->diffInMinutes($payment->expires_at))).' minutes' : '').'.'
                : "The shop CONFIRMED the reservation {$what}.{$note} Tell the customer it is confirmed and give them the code {$reservation->code}.",
            ReservationService::ACTION_DECLINE => "The shop could NOT accept the reservation request {$what}.{$note} Apologise, and offer to look for another day or time with check_availability.",
            ReservationService::ACTION_OFFER => 'The shop cannot do the reservation request '.$what.', but OFFERS '
                .($reservation->localOffered($timezone)?->format('l Y-m-d \a\t H:i') ?? 'another time')
                .' instead.'.$note." Ask the customer whether that time works. If they agree, call accept_offered_time with reservation_id {$reservation->id}. If not, ask what else suits them.",
            ReservationService::ACTION_CANCEL => "The shop CANCELLED the reservation {$what}.{$note} Tell the customer and apologise.",
            ReservationService::ANNOUNCE_PAID => "The payment for the reservation {$what} arrived: it is CONFIRMED. Thank the customer and give them the code {$reservation->code}.",
            ReservationService::ANNOUNCE_EXPIRED => "The payment for the reservation {$what} was not made in time, so the time was released. Tell the customer, and offer to book again (check_availability, then request_reservation).",
            default => "The reservation {$what} was updated by the shop.{$note} Tell the customer.",
        };

        return '[Reservation update from the shop — not a message from the customer. '
            .'Tell the customer now, briefly and naturally, in the language of the conversation, without greeting them again.] '
            .$instruction;
    }

    /** "K7QF3M (Mesa, Saturday 2026-11-07 at 19:00, 6 people)" */
    private static function describe(Reservation $reservation, string $when): string
    {
        $people = $reservation->party_size.' '.($reservation->party_size === 1 ? 'person' : 'people');

        return "{$reservation->code} ({$reservation->service_name}, {$when}, {$people})";
    }
}
