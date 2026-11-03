<?php

namespace App\Services\Message\Contracts;

use App\Models\Conversation;
use App\Models\Message;

/**
 * A send handler whose channel can draw a Pix key as the card WhatsApp shows
 * for it — the name, the key and a "Copiar chave Pix" button.
 *
 * A capability interface for the same reason as SendsTypingIndicator: only
 * the two WhatsApp channels have anything of the kind, and a method on every
 * other handler would only ever throw. Callers go through
 * MessageService::sendPixKey(), which checks for it — and the Pix key node
 * sends the same key as text wherever this is missing.
 *
 * Unlike the indicators, a refusal **throws**: the caller has the text version
 * to fall back on, and can only use it if it is told.
 */
interface SendsPixKey
{
    /**
     * @param  array{key_type: string, key: string, merchant_name: string, amount_cents: int|null, message: string, image_url: string|null, description: string}  $pix
     *                                                                                                                                                                  a PixKeyNodes::resolve() result
     */
    public function handleSendPixKey(Conversation $conversation, array $pix): ?Message;
}
