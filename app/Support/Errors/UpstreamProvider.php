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

    /**
     * The group's own payment service, which owns every gateway account.
     *
     * Named after the service and not after a gateway on purpose: which of
     * dLocal, MercadoPago or OpenPIX took a charge is decided over there and
     * changes without notice, so a case per gateway would be a dictionary that
     * silently stopped matching.
     */
    case PaymentService = 'payment_service';

    /** The tenant's own mail server (SMTP/IMAP). */
    case Email = 'email';

    /*
     * The workspace's own accounts, connected on the Integrations page.
     *
     * Named after the vendor, unlike the platform's suppliers above — and that
     * is the same rule, not an exception to it. The customer never heard of
     * ProxyBR; they did sign up to OpenPix, paste its key into our form, and
     * can open its dashboard. Here the vendor's name is the actionable part.
     */

    case OpenPix = 'openpix';

    case MercadoPago = 'mercadopago';

    case MetaPixel = 'meta_pixel';

    case GoogleAnalytics = 'google_analytics';

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
            self::PaymentService => 'o processamento de pagamentos',
            self::Email => 'o servidor de e-mail',
            self::OpenPix => 'a OpenPix',
            self::MercadoPago => 'o Mercado Pago',
            self::MetaPixel => 'o Pixel da Meta',
            self::GoogleAnalytics => 'o Google Analytics',
            self::Unknown => 'o serviço externo',
        };
    }
}
