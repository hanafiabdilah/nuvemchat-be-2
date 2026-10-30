<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\Crm\CustomerResolver;

/**
 * A new person writing in is a CRM customer from their first message: every
 * contact created — by a webhook, the widget, a history import, an operator —
 * is linked to the customer with the same phone or e-mail, or to a new one.
 * CustomerResolver never throws, so a webhook cannot fail on this.
 */
class ContactObserver
{
    public function __construct(
        private readonly CustomerResolver $resolver,
    ) {}

    public function created(Contact $contact): void
    {
        $this->resolver->attach($contact);
    }
}
