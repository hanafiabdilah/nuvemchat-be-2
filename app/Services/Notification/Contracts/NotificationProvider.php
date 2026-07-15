<?php

namespace App\Services\Notification\Contracts;

/**
 * A transport that can deliver a platform notification (e.g. WhatsApp via W-API).
 *
 * Implement this + register the class in NotificationProviderFactory to add a
 * new provider (another WhatsApp API, SMS, email, etc.). Everything else — the
 * event catalog, config, and dispatch flow — stays untouched.
 */
interface NotificationProvider
{
    /** Stable key used in settings (e.g. 'wapi'). */
    public function key(): string;

    /** Whether the provider has the credentials it needs to send. */
    public function isConfigured(): bool;

    /**
     * Deliver a plain-text message to a recipient.
     *
     * @param string $to      Recipient identifier (WhatsApp phone in E.164 for WA providers).
     * @param string $message Already-rendered message body.
     */
    public function send(string $to, string $message): void;
}
