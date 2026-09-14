<?php

namespace App\Enums\Broadcast;

/**
 * Where a campaign's recipient list came from — which decides what a
 * recipient row means when its turn comes.
 *
 *   campaign → an address (picked contact or typed number). The sender finds
 *              or opens the conversation, assigns it, and tags it.
 *   inbox    → a thread an agent selected in the inbox. The conversation
 *              already exists and is already theirs, so the sender writes into
 *              exactly that thread and touches nothing else about it — no
 *              campaign tag, no reassignment — and can resolve it afterwards.
 */
enum Source: string
{
    case Campaign = 'campaign';
    case Inbox = 'inbox';
}
