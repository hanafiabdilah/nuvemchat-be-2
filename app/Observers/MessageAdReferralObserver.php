<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\Sales\AdReferrals;

/**
 * Notes the ad a customer arrived from, off the message that carries it.
 *
 * On the model rather than in each channel handler: two handlers deliver ad
 * clicks today and a third would have to remember this. AdReferrals::capture
 * does nothing for a message with no ad in it, which is nearly all of them.
 */
class MessageAdReferralObserver
{
    public function created(Message $message): void
    {
        AdReferrals::capture($message);
    }
}
