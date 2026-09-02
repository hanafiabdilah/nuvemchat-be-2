<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\AiHubAgent;
use App\Models\Setting;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for the AIAgent input suites (images, voice notes).
 *
 * Lives outside tests/Feature so Pest does not try to run it, and as a class
 * rather than loose functions because Pest loads every test file into one
 * process — two files declaring `hubRuns()` is a fatal error, not a failure.
 */
class AiAgentFixtures
{
    /**
     * A WhatsApp connection parked on an AIAgent node, with a hub agent behind
     * it.
     *
     * @return array{0: Conversation, 1: FlowNode}
     */
    public static function flow(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $hubTenant = AiHubTenant::create([
            'tenant_id' => $tenant->id,
            'hub_tenant_id' => 'hub-tenant-1',
            'external_id' => 'Pingly_1',
            'name' => 'Pingly_1',
            'status' => 'ACTIVE',
        ]);

        // Auth to the hub is platform-level: Pingly is one tenant there, so a
        // single token stands behind every workspace's calls.
        Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

        $agent = AiHubAgent::create([
            'ai_hub_tenant_id' => $hubTenant->id,
            'hub_agent_id' => 'hub-agent-1',
            'external_id' => 'agente_atendimento',
            'name' => 'Atendimento',
            'model' => 'gpt-4o-mini',
            'status' => 'ACTIVE',
        ]);

        $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Suporte']);

        $start = $flow->nodes()->create([
            'type' => NodeType::Start,
            'data' => null,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $ai = $flow->nodes()->create([
            'type' => NodeType::AIAgent,
            'data' => [
                'ai_hub_agent_id' => $agent->id,
                'welcoming_message' => 'Oi! Como posso ajudar?',
            ],
            'position_x' => 100,
            'position_y' => 0,
        ]);

        FlowEdge::create([
            'source_node_id' => $start->id,
            'target_node_id' => $ai->id,
            'condition_value' => null,
        ]);

        $connection = Connection::create([
            'tenant_id' => $tenant->id,
            'channel' => Channel::WhatsappOfficial,
            'name' => 'WA',
            'color' => '#22c55e',
            'status' => ConnectionStatus::Active,
            'flow_id' => $flow->id,
            'credentials' => [
                'phone_number_id' => '1083508778182246',
                'access_token' => 'wa-token',
                'business_account_id' => '222000222',
            ],
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'channel' => Channel::WhatsappOfficial,
            'external_id' => '5511999999999',
            'name' => 'Ana',
            'username' => '5511999999999',
        ]);

        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => '5511999999999',
            'status' => ConversationStatus::Pending,
        ]);

        return [$conversation, $ai];
    }

    /** Outbound WhatsApp and the hub both answer; the hub's reply is $aiReply. */
    public static function fakeChannelsAndHub(string $aiReply = 'Vi o erro no seu print.', array $extra = [], array $output = []): void
    {
        Http::fake(array_merge($extra, [
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
            'api-ia.ipbr.pro/*' => Http::response([
                'id' => 'run_1',
                'status' => 'COMPLETED',
                'output' => array_merge(['message' => $aiReply, 'handoff' => false], $output),
            ]),
        ]));
    }

    /**
     * Get the conversation past the welcome turn — the first interaction is
     * always answered by the canned greeting, never by the AI.
     */
    public static function openWithWelcome(Conversation $conversation): void
    {
        $conversation->messages()->create([
            'external_id' => 'wamid.IN0',
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'Oi',
            'sent_at' => now(),
        ]);

        (new FlowExecutor)->startFlow($conversation);
    }

    public static function incomingMedia(
        Conversation $conversation,
        MessageType $type,
        ?string $body,
        ?string $attachment,
        ?AttachmentStatus $status = null
    ): Message {
        return $conversation->messages()->create([
            'external_id' => 'wamid.' . uniqid(),
            'sender_type' => SenderType::Incoming,
            'message_type' => $type,
            'body' => $body,
            'attachment' => $attachment,
            'attachment_status' => $status,
            'sent_at' => now(),
        ]);
    }

    /**
     * Enough of a real Ogg/Opus file for the `mimes:` rule on the send path:
     * the validator guesses the type from the bytes, and "fake-audio" guesses
     * as plain text. A first page carrying the OpusHead packet is all libmagic
     * reads.
     */
    public static function opusBytes(): string
    {
        $body = "OpusHead" . pack('Cx', 1) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . "\x00\x00";

        $header = 'OggS' . chr(0) . chr(2) . str_repeat("\x00", 8)
            . pack('V', 12345) . pack('V', 0) . pack('V', 0)
            . chr(1) . chr(strlen($body));

        return $header . $body . str_repeat("\x00", 32);
    }

    /**
     * The run payloads the hub received, in order.
     *
     * Matched on the endpoint, not just the host: a voice reply fetches its
     * generated file from the hub too, and counting that GET as a run shifts
     * every index by one.
     */
    public static function hubRuns(): array
    {
        $runs = [];

        foreach (Http::recorded() as [$request, $response]) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/runs')) {
                $runs[] = $request->data();
            }
        }

        return $runs;
    }
}
