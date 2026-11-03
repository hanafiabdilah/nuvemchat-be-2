<?php

namespace App\Services\Message\Contracts;

use App\Models\Conversation;

/**
 * A send handler whose channel can ring the customer — the flow's Call node.
 *
 * A capability interface for the same reason as SendsPixKey: only API Way can
 * place a call, through its core, and a method on every other handler would
 * only ever throw. Callers go through MessageService::placeCall().
 *
 * The call runs on after this returns: the core accepts it, says how long it
 * can last, and reports how it ended later, as a webhook event. A refusal
 * throws.
 */
interface PlacesCalls
{
    /**
     * @param  int  $ringSeconds  how long it rings before the core gives up
     * @param  string|null  $audioUrl  a recording played when the customer answers
     * @return array{call_id: string, max_seconds: int|null, audio: bool}
     *                                                                    `max_seconds`: the longest the call can run (ring plus
     *                                                                    recording), when the core said; `audio`: whether the
     *                                                                    recording was accepted along with the call
     */
    public function handlePlaceCall(Conversation $conversation, int $ringSeconds, ?string $audioUrl): array;
}
