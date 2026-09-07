<?php

namespace App\Support\Errors;

use App\Enums\Connection\Channel;

/**
 * Which outside system refused, from the tenant's point of view.
 *
 * The cases are named after what the customer bought, not after who we call to
 * deliver it: a business owner has a "WhatsApp connection" and an "AI agent",
 * they have never heard of ProxyBR, whatsmeow, NestJS or ElevenLabs. The enum
 * exists so a failure can be translated into words about *their* thing — and so
 * the raw upstream wording has exactly one place it is allowed to end up: the
 * log line UpstreamError writes.
 */
enum UpstreamProvider: string
{
    /** api-ia.ipbr.pro — agents, runs, credentials, transcription, TTS. */
    case AiHub = 'ai_hub';

    /** ProxyBR partner API — buying, renewing and cancelling instances. */
    case ApiwayPartner = 'apiway_partner';

    /** whats-api.ipbr.pro — the running instance: QR, status, sending. */
    case ApiwayCore = 'apiway_core';

    /** portal.apiway.com.br — renting SMS/OTP numbers. */
    case ApiwayNumbers = 'apiway_numbers';

    /** Graph API: WhatsApp Cloud, Instagram, Messenger. */
    case Meta = 'meta';

    case Telegram = 'telegram';

    case Discord = 'discord';

    case TikTok = 'tiktok';

    case MercadoPago = 'mercadopago';

    /** The tenant's own mail server (SMTP/IMAP). */
    case Email = 'email';

    /** Something outside we could not attribute; always the vaguest copy. */
    case Unknown = 'unknown';

    /**
     * The provider behind a channel, for the one chokepoint that only knows
     * which inbox it was writing to (MessageService).
     */
    public static function forChannel(Channel $channel): self
    {
        return match ($channel) {
            Channel::WhatsappOfficial, Channel::Instagram, Channel::Messenger => self::Meta,
            Channel::WhatsappApiway => self::ApiwayCore,
            Channel::Telegram => self::Telegram,
            Channel::Discord => self::Discord,
            Channel::TikTok => self::TikTok,
            Channel::Email => self::Email,
            default => self::Unknown,
        };
    }

    /**
     * How the platform names this thing to the person reading the error.
     *
     * Never the vendor: "o provedor da instância" rather than "ProxyBR",
     * "o serviço de IA" rather than "AI Hub". The customer cannot act on a
     * vendor name, and printing one turns our outage into their research
     * project.
     */
    public function label(): string
    {
        return match ($this) {
            self::AiHub => 'o serviço de IA',
            self::ApiwayPartner => 'o provedor de instâncias',
            self::ApiwayCore => 'a instância do WhatsApp',
            self::ApiwayNumbers => 'o provedor de números',
            self::Meta => 'o canal',
            self::Telegram => 'o Telegram',
            self::Discord => 'o Discord',
            self::TikTok => 'o TikTok',
            self::MercadoPago => 'o provedor de pagamentos',
            self::Email => 'o servidor de e-mail',
            self::Unknown => 'o serviço externo',
        };
    }
}
