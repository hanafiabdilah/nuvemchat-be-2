<?php

namespace App\Enums\Incident;

/**
 * Who writes the notice.
 *
 * `fixed` sends the incident's message as written. `ai` has one of the
 * workspace's AI agents write it per customer, from the incident's brief and
 * what that customer said — in their language, answering the rest of their
 * question too. The fixed message stays required in `ai`: it is what goes out
 * when the AI cannot answer, so a customer is never left without the notice.
 */
enum ReplyMode: string
{
    case Fixed = 'fixed';
    case Ai = 'ai';
}
