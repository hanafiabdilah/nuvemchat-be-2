<?php

namespace App\Enums\Connection;

use App\Enums\Broadcast\AddressType;

enum Channel: string
{
    case Instagram = 'instagram';
    case WhatsappOfficial = 'whatsapp_official';
    // Branded "API Way". The backing value stays `whatsapp_proxyhub` because it is
    // persisted in `connections.channel` — renaming it would orphan existing rows.
    case WhatsappApiway = 'whatsapp_proxyhub';
    case Telegram = 'telegram';
    case LiveChatWidget = 'live_chat_widget';
    case Email = 'email';
    case TikTok = 'tiktok';
    case Messenger = 'messenger';
    case Discord = 'discord';

    /**
     * Whether an agent may open a conversation here, i.e. send the very first
     * message. False on channels where the platform only ever allows replying
     * inside a session the customer opened:
     *
     *  - WhatsApp Official: free-form content is limited to 24h after the
     *    customer's last message. Re-engaging goes through an approved
     *    template instead (see MessageTemplateController::send).
     *  - TikTok: same shape, 48h, and there is no template equivalent.
     *
     * Instagram and Messenger carry the same platform limitation but are left
     * alone here — their contacts already cannot be created manually, which
     * keeps them out of the flow in practice.
     */
    public function canStartConversation(): bool
    {
        return ! in_array($this, [self::WhatsappOfficial, self::TikTok], true);
    }

    /**
     * Whether a broadcast campaign may run on this channel at all.
     *
     * Only the live chat widget is out: its sessions exist because a visitor
     * opened one on the site, so there is no address to reach anybody at
     * afterwards.
     *
     * Note this is deliberately wider than canStartConversation(): WhatsApp
     * Official cannot open a thread with free-form content but can with an
     * approved template, which is exactly what a campaign sends.
     */
    public function supportsBroadcast(): bool
    {
        return $this !== self::LiveChatWidget;
    }

    /**
     * How a recipient is addressed here, which is what decides whether a
     * campaign may accept typed-in recipients at all:
     *
     *  - Phone / Email are things a human can type, so a campaign may mix
     *    picked contacts with a pasted list.
     *  - Internal means the address is a platform id (Telegram chat id, Discord
     *    user id, an Instagram-scoped id) nobody has to hand — those campaigns
     *    can only draw from contacts that already exist.
     */
    public function broadcastAddressType(): AddressType
    {
        return match ($this) {
            self::WhatsappOfficial, self::WhatsappApiway => AddressType::Phone,
            self::Email => AddressType::Email,
            default => AddressType::Internal,
        };
    }

    /**
     * Whether a campaign here must carry an approved message template.
     *
     * WhatsApp Official is the only one. A campaign reaches people who are not
     * currently chatting, i.e. outside the 24h session window, and the Cloud
     * API accepts nothing but a template there — see MessagingWindow, and note
     * that an outgoing message never opens the window itself.
     */
    public function broadcastRequiresTemplate(): bool
    {
        return $this === self::WhatsappOfficial;
    }

    /**
     * Messages per minute a new campaign defaults to.
     *
     * Deliberately conservative: the ceiling that matters is rarely the API's
     * own. API Way rides on an unofficial WhatsApp client where a fast, regular
     * send pattern is what gets a number banned, so it sits an order of
     * magnitude below the rest (and pauses randomly between sends — see
     * broadcastNeedsSendJitter()).
     */
    public function broadcastDefaultRatePerMinute(): int
    {
        return match ($this) {
            self::WhatsappOfficial => 300,
            self::WhatsappApiway => 12,
            self::Telegram => 600,
            self::Discord, self::TikTok => 30,
            default => 60,
        };
    }

    /** Hard ceiling an operator may raise the rate to on this channel. */
    public function broadcastMaxRatePerMinute(): int
    {
        return match ($this) {
            self::WhatsappOfficial, self::Telegram => 1200,
            // Not a technical limit — a deliberate brake on the channel most
            // likely to get the tenant's number banned for blasting.
            self::WhatsappApiway => 60,
            default => 600,
        };
    }

    /**
     * Whether sends should be spaced by a random pause instead of an even one.
     * Only matters where a machine-regular cadence is itself the tell.
     */
    public function broadcastNeedsSendJitter(): bool
    {
        return $this === self::WhatsappApiway;
    }

    /**
     * How often the agent's "typing…" has to be re-asserted to stay lit here,
     * in seconds. Null means the channel has no typing indicator at all.
     *
     * Every one of these indicators is a dead man's switch, not a state: the
     * platform lights it and starts a countdown, and the only way to keep it up
     * is to say it again. So the number that matters is not "how long does it
     * last" but "how soon must we repeat", and it is that second number both
     * sides need — the SPA to pace its calls, this side to reject anything
     * faster. Each sits comfortably inside the platform's own timeout so a slow
     * request does not leave a visible gap:
     *
     *   WhatsApp Official  25s (or until the reply lands)
     *   Messenger / IG     ~20s, and typing_off exists
     *   Discord            10s exactly, with no way to clear it early
     *   Telegram            5s — far the shortest, hence the tightest pace
     *   API Way            no timeout: `composing` holds until `paused` is sent,
     *                      which is also why it is the one channel where
     *                      forgetting to stop leaves it lit for good
     *   Live Chat Widget   ours; the widget expires it on its own
     *
     * TikTok has no such API, and e-mail has no such idea.
     */
    public function typingRefreshSeconds(): ?int
    {
        return match ($this) {
            self::Telegram => 4,
            self::Discord => 8,
            self::WhatsappOfficial, self::WhatsappApiway,
            self::Instagram, self::Messenger, self::LiveChatWidget => 10,
            default => null,
        };
    }

    /** Whether an agent's typing can be shown to the customer here at all. */
    public function supportsTypingIndicator(): bool
    {
        return $this->typingRefreshSeconds() !== null;
    }

    /**
     * Whether "we have read you" can be reflected back onto the channel.
     *
     * Opening a thread has always cleared the badge on our side; this is the
     * question of whether the *customer* can be told, in the app they are
     * actually looking at. The panel asks this before queueing any work, so a
     * channel that cannot do it costs nothing at all:
     *
     *   WhatsApp Official  status:read on the message id — marks every earlier
     *                      message read too, so one call settles the thread
     *   API Way            the same idea through the core (see the handler: the
     *                      route is the one unverified piece of this feature)
     *   Instagram          sender_action: mark_seen
     *   Messenger          sender_action: mark_seen
     *   Live Chat Widget   ours — an event on the visitor's own session channel,
     *                      which is what turns their ticks blue live
     *   E-mail             the only one where "the channel" is a mailbox rather
     *                      than a person: the IMAP \Seen flag, so mail an agent
     *                      has answered here stops sitting bold in Gmail
     *
     * The three that are out are out for good reasons, not for lack of work:
     * a Telegram *bot* has no read API (readBusinessMessage belongs to Business
     * accounts, which these are not), Discord's ack endpoint refuses bots, and
     * TikTok sends us read events without accepting any.
     */
    public function supportsReadReceipt(): bool
    {
        return match ($this) {
            self::WhatsappOfficial, self::WhatsappApiway,
            self::Instagram, self::Messenger,
            self::LiveChatWidget, self::Email => true,
            default => false,
        };
    }

    /**
     * Whether the channel tells *us* that a message we sent reached the
     * handset. The mirror image of supportsReadReceipt(), which is about the
     * receipts we send outward — do not confuse the two.
     *
     * Only these three feed `messages.delivery_at` from an actual confirmation:
     * WhatsApp Official's `delivered` status webhook, API Way's whatsmeow
     * receipt event, and Messenger's delivery watermark. Instagram sends read
     * events but no delivery ones; Telegram and TikTok stamp `delivery_at`
     * themselves the moment the API accepts the send, which makes their rate
     * 100% by construction and therefore worth nothing; the rest report
     * nothing at all.
     *
     * A delivery rate is only honest where this is true. Everywhere else the
     * UI must omit the metric rather than render a rate that is really a
     * measure of which channel you happen to be on.
     */
    public function reportsDeliveryReceipts(): bool
    {
        return match ($this) {
            self::WhatsappOfficial, self::WhatsappApiway, self::Messenger => true,
            default => false,
        };
    }

    /**
     * Whether an AI agent can answer here with a voice note.
     *
     * Every channel below already sends agent-recorded audio, so this asks a
     * narrower question: is a spoken reply a message on this channel, or an
     * attachment? TikTok is the only one that flatly cannot (text and image
     * only). E-mail can carry the file, and that is exactly why it is
     * excluded — an MP3 attached to a mail is not somebody answering you, and
     * the AI hub has no channel mapping for e-mail anyway.
     */
    public function supportsVoiceReply(): bool
    {
        return match ($this) {
            self::TikTok, self::Email => false,
            default => true,
        };
    }

    /**
     * The audio format to ask the AI hub for, so the reply arrives as the
     * right *kind* of message here.
     *
     * This is not about playback — every channel below plays an MP3 — but
     * about which bubble the recipient gets. WhatsApp draws a voice note, with
     * the waveform and the play-once behaviour people expect from someone
     * talking to them, only for Ogg/Opus; an MP3 lands as a file attachment
     * that reads like a document somebody sent. Same audio, different message.
     *
     * Everything else stays on MP3 deliberately. Telegram would need
     * `sendVoice` rather than `sendAudio` to draw the voice bubble, so an Opus
     * file there buys nothing and only narrows what can play it; Discord,
     * Instagram, Messenger and the widget have no voice-note concept at all,
     * where MP3 is the safest thing to hand a browser.
     */
    public function voiceReplyFormat(): string
    {
        return match ($this) {
            self::WhatsappOfficial, self::WhatsappApiway => 'opus',
            default => 'mp3',
        };
    }
}
