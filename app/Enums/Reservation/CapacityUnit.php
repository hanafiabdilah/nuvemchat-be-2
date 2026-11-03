<?php

namespace App\Enums\Reservation;

/**
 * What a service's capacity counts.
 *
 * `bookings`: each reservation takes one place, however many people come —
 * three chairs at a barber, two courts. `people`: each reservation takes as
 * many places as its party size — sixty seats in a restaurant. With `people`,
 * the price and the deposit are per person.
 */
enum CapacityUnit: string
{
    case Bookings = 'bookings';
    case People = 'people';
}
