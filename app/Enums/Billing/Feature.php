<?php

namespace App\Enums\Billing;

/**
 * Canonical billing feature keys.
 *
 * Canonical quota keys:
 * - max_connections
 * - max_agents
 * - max_instances
 * - max_proxies
 */
enum Feature: string
{
    case Chat = 'chat';
    case WhatsappApi = 'whatsapp_api';
    case Proxy = 'proxy';
}
