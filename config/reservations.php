<?php

/*
 * Reservations — see App\Services\Reservations\ReservationService.
 */

return [

    /*
     * How many open reservations (pending, offered, awaiting payment or
     * confirmed, still ahead) the AI may hold for one customer at once. A
     * bound, not a business rule: without it one conversation could book a
     * day of slots one tool call at a time.
     */
    'max_upcoming_per_contact' => (int) env('RESERVATIONS_MAX_UPCOMING_PER_CONTACT', 5),

    /*
     * Minutes a request may wait for the team before the operators are
     * reminded of it once (toast). 0 = no reminder.
     */
    'review_reminder_minutes' => (int) env('RESERVATIONS_REVIEW_REMINDER_MINUTES', 15),

    /*
     * How long a request taken without a service lasts, for its end time —
     * there is no service to read a duration from.
     */
    'default_duration_minutes' => 60,

    /*
     * How many free start times one availability answer hands the model.
     * More costs tokens on every turn and makes the model misread the list.
     */
    'max_slots_for_model' => 24,

];
