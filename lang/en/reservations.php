<?php

/**
 * Reservations (App\Services\Reservations, Api\Reservations)
 *
 * lang/en, lang/id and lang/pt_BR have the same keys.
 */

return [
    'not_open' => 'This reservation can no longer be answered — it was already decided, or its time has passed.',
    'offer_time_required' => 'Choose the date and time to offer.',
    'offer_in_the_past' => 'The time to offer has already passed.',
    'service_required' => 'Choose a service.',
    'service_has_upcoming' => 'This service has upcoming reservations. Switch it off to stop new bookings, or cancel them first.',
    'only_manual_delete' => 'Only reservations written down by the team can be deleted. Cancel this one instead.',
    'deposit_required' => 'Enter the deposit amount.',
    'price_required' => 'Enter the price.',
    'invalid_date' => 'Use a date in the format YYYY-MM-DD.',
    'invalid_time' => 'Use a time in the format HH:MM.',
    'conversation_exclusive' => 'This conversation is exclusive to another agent.',
    'problems' => [
        'inactive' => 'This service is not taking reservations.',
        'closed' => 'The service is closed at that time.',
        'not_a_slot' => 'That time is outside the opening hours, or the reservation does not fit before closing.',
        'in_the_past' => 'That time has already passed.',
        'too_soon' => 'That is sooner than the service books.',
        'too_far' => 'That is further ahead than the service books.',
        'full' => 'That time is fully booked.',
        'day_full' => 'That day is marked as fully booked.',
        'party_too_large' => 'More people than the service takes per reservation.',
    ],
];
