<?php

namespace App\Enums\Message;

/**
 * What the customer is being shown while they wait: "digitando…", "gravando
 * áudio…" or "enviando arquivo…".
 *
 * Until now every indicator this platform sent was typing, because the only
 * caller was a composer with somebody's fingers on a keyboard. A flow is
 * different: the pause before an audio bubble is a pause before a *voice note*,
 * and WhatsApp draws that differently from typing — which is exactly what the
 * customer needs to see, because "gravando áudio" is a promise about what is
 * coming and "digitando" is the wrong one.
 *
 * ⚠️ Most channels cannot tell these apart, and that is not a reason to hold
 * the distinction back on the ones that can. A channel that only knows typing
 * shows typing: the point of the indicator is "somebody is preparing a reply",
 * and the specific verb is a refinement, not the message. So a kind is always a
 * *request*, never a requirement — see each handler for what it does with it:
 *
 *   Telegram   all three, natively (typing / record_voice / upload_document)
 *   API Way    typing and recording (whatsmeow's `media` attribute on the
 *              composing state is what makes WhatsApp say "gravando áudio")
 *   WA Cloud   typing only — Meta's typing_indicator accepts `text` and
 *              nothing else
 *   IG / Messenger / Discord   typing only
 *   Widget     ours, so the kind rides along in the event for the widget to
 *              render when it learns how
 */
enum PresenceKind: string
{
    case Typing = 'typing';

    case Recording = 'recording';

    case Uploading = 'uploading';

    /**
     * The indicator that fits the bubble about to be sent.
     *
     * Audio is the one this exists for. Image, video and documents are an
     * upload rather than a composition — nobody "types" a PDF — and text falls
     * back to typing, which is also where anything unrecognised lands.
     */
    public static function forMessageType(?string $messageType): self
    {
        return match ($messageType) {
            'audio' => self::Recording,
            'image', 'video', 'document' => self::Uploading,
            default => self::Typing,
        };
    }

    /**
     * The Telegram chat action. `upload_document` rather than a per-type guess:
     * the node only knows it is sending a file, and "enviando arquivo" is true
     * of all three.
     */
    public function telegramAction(): string
    {
        return match ($this) {
            self::Typing => 'typing',
            self::Recording => 'record_voice',
            self::Uploading => 'upload_document',
        };
    }

    /**
     * whatsmeow's `media` attribute on the composing chat state. Only audio has
     * a value there — everything else is the plain composing state, which is
     * what "text" means in the core's API.
     */
    public function whatsmeowMedia(): string
    {
        return $this === self::Recording ? 'audio' : 'text';
    }
}
