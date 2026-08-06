<?php

namespace App\Enums\Billing;

/**
 * Canonical billing feature keys.
 *
 * Canonical quota keys:
 * - max_connections
 * - max_agents
 * - included_instances (API Way instances provisioned free with the plan)
 */
enum Feature: string
{
    case Chat = 'chat';
    case WhatsappApi = 'whatsapp_api';
}
