<?php

namespace App\Services\Contact\Profile;

/**
 * The display identity a channel reports for one contact.
 *
 * Both fields are optional because channels answer partially: an Instagram
 * account without a display name returns a username only, and the syncer has
 * to be able to tell "the channel said nothing about this field" from "the
 * channel said it is empty".
 */
class ContactProfile
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $username = null,
    ) {}
}
