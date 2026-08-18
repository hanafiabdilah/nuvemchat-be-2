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
}
