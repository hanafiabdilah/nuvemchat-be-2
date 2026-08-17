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

    /**
     * The sales funnel: leads, pipelines, the board. Separate from `chat`
     * because a workspace can run a busy support inbox and never sell anything
     * from it — and because it is the natural thing to put on a higher tier.
     */
    case Crm = 'crm';
}
