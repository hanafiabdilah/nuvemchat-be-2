<?php

namespace App\Enums\Conversation;

enum Type: string
{
    /** 1:1 conversation with a single contact. */
    case Private = 'private';

    /**
     * Multi-participant conversation (e.g. a Telegram group). contact_id points
     * at the contact row representing the group itself (contacts.is_group);
     * the actual sender of each incoming message lives in messages.contact_id.
     */
    case Group = 'group';
}
