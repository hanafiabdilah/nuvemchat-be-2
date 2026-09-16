<?php

namespace Tests\Support;

use App\Models\AiHubAgent;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Models\User;

/**
 * Setup for the proactive-message suite: a WhatsApp conversation actually being
 * served by an AI node, plus the workspace API key the hub would hold.
 *
 * Built on top of AiAgentFixtures rather than beside it, so the flow state the
 * callback reference is checked against is the one the real engine wrote.
 *
 * A class, not Pest helper functions: those are global, and Pest loads every
 * test file into one process.
 */
final class ProactiveMessageFixtures
{
    /**
     * A conversation mid-turn with an AI agent.
     *
     * @return array{conversation: Conversation, node: FlowNode, agent: AiHubAgent, tenant: Tenant, key: string}
     */
    public static function scenario(): array
    {
        [$conversation, $node] = AiAgentFixtures::flow();

        AiAgentFixtures::fakeChannelsAndHub();
        AiAgentFixtures::openWithWelcome($conversation);

        $conversation->refresh();

        $tenant = $conversation->connection->tenant;
        $owner = User::where('tenant_id', $tenant->id)->firstOrFail();

        [, $plain] = ApiKey::issue($tenant, 'AI Hub', $owner);

        return [
            'conversation' => $conversation,
            'node' => $node,
            'agent' => AiHubAgent::findOrFail($node->data['ai_hub_agent_id']),
            'tenant' => $tenant,
            'key' => $plain,
        ];
    }
}
