<?php

namespace App\Services\FlowAssistant;

use App\Models\Setting;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Flow\FlowBlueprint;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The flow builder's AI assistant: it turns "atendimento para uma pizzaria"
 * into a flow the builder can draw, and it edits the flow already on the
 * canvas.
 *
 * ── The one promise this class makes ──
 *
 * A blueprint it returns is a blueprint the save endpoint accepts. Everything
 * unusual in here follows from that. The model's output is validated against
 * {@see FlowBlueprint} — the same rules saving and importing use — and a
 * blueprint that fails is handed back to the model with its errors rather than
 * forwarded to the browser. If it still fails, the caller gets prose and no
 * flow. A generated flow that lands on the canvas and then refuses to save is
 * worse than no assistant: the customer cannot tell whether they asked for
 * something impossible or the product is broken, and the canvas is now dirty.
 *
 * ── Why this does not go through AiAgentHubTenantService::runAgent ──
 *
 * That method exists to answer a customer, and everything it does around the
 * HTTP call says so: it needs a real Conversation, it counts against the
 * workspace's `max_ai_runs`, it can spend the workspace's prepaid balance, and
 * it writes an `ai_hub_runs` row keyed to a local agent. None of that is true
 * here. The assistant is a platform tool running on the platform's own OpenAI
 * key: no conversation, no workspace quota, nothing to bill. Reusing that
 * method would mean weakening every one of those guarantees for the one caller
 * that must not have them.
 *
 * ── Turns are stateless, on purpose ──
 *
 * The hub keeps conversation state keyed by `conversation.externalId`, and it
 * would be tempting to let it remember the dialogue. But the largest thing in
 * every turn is the current flow, it changes with every turn, and hub-side
 * history would accumulate one full copy per turn until the context is mostly
 * stale versions of the same graph. So each turn gets a fresh external id and
 * carries its own context: the recent transcript (words only — never past
 * blueprints) plus the flow as it stands right now.
 */
class FlowAssistantService
{
    /**
     * Ceiling on a generated flow. Not a product limit — the builder happily
     * holds more — but a runaway guard: a model that starts enumerating a
     * hundred menu options produces something nobody will read, review or fix,
     * and the honest answer is to ask for it in pieces.
     */
    private const MAX_NODES = 60;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = AiAgentHubConfig::baseUrl();
    }

    // ─────────────────────────── Provisioning ───────────────────────────

    /**
     * Register (or refresh) the platform's OpenAI credential and assistant
     * agent at the hub. Called from the Back Office when an operator saves the
     * key or the model.
     *
     * Idempotent by design: it PATCHes what already exists and only creates
     * what does not, so pressing Save twice does not leave two agents behind —
     * and re-pasting a rotated key is the same action as setting it the first
     * time.
     *
     * @param  string|null  $apiKey  null keeps the stored key (the operator is
     *                               only changing the model)
     * @return array{credential_id: string, agent_external_id: string, model: string}
     */
    public function provision(?string $apiKey = null, ?string $model = null): array
    {
        $apiKey = $apiKey ?: FlowAssistantConfig::apiKey();
        $model = $model ?: FlowAssistantConfig::model();

        if (! $apiKey) {
            throw ValidationException::withMessages([
                'flow_assistant.openai_api_key' => ['An OpenAI API key is required before the flow assistant can be provisioned.'],
            ]);
        }

        $credentialId = $this->ensureCredential($apiKey, $model);
        $externalId = $this->ensureAgent($credentialId, $model);

        Setting::set(FlowAssistantConfig::KEY_API_KEY, $apiKey);
        Setting::set(FlowAssistantConfig::KEY_MODEL, $model);
        Setting::set(FlowAssistantConfig::KEY_HUB_CREDENTIAL_ID, $credentialId);
        Setting::set(FlowAssistantConfig::KEY_AGENT_EXTERNAL_ID, $externalId);
        Setting::set(FlowAssistantConfig::KEY_PROMPT_HASH, $this->promptHash());

        return [
            'credential_id' => $credentialId,
            'agent_external_id' => $externalId,
            'model' => $model,
        ];
    }

    /**
     * The hub credential holding the platform's OpenAI key, created or updated.
     *
     * ⚠️ `providerCredentialId` at the hub is an id, never the key itself —
     * the lesson `AiTranscription::credentialId()` was written after. What we
     * store below is the id the hub hands back.
     */
    private function ensureCredential(string $apiKey, string $model): string
    {
        $existing = FlowAssistantConfig::hubCredentialId();

        $payload = [
            'name' => 'Pingly platform — flow assistant',
            'apiKey' => $apiKey,
            'defaultModel' => $model,
        ];

        if ($existing) {
            $response = Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/provider-credentials/{$existing}", $payload);

            // A hub that no longer knows this credential (rebuilt, or the row
            // deleted on their side) is not a failure to report — it is a
            // credential to create. Falling through is what keeps an operator
            // from having to know that.
            if ($response->successful()) {
                return $existing;
            }

            Log::warning('FlowAssistantService: stored hub credential did not update, re-creating', [
                'hub_credential_id' => $existing,
                'status' => $response->status(),
            ]);
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/provider-credentials", $payload + [
                'provider' => 'OPENAI',
                'metadata' => ['ownerType' => 'platform', 'usage' => ['flow_assistant']],
            ]);

        // ⚠️ The hub uniques a credential on (tenant, provider, name), so once
        // one exists under this name every re-create answers 409 — and if the
        // settings row holding its id was ever lost, provisioning would be
        // permanently stuck with no way out from any screen. This is the same
        // dead end AiTokenRentalService::rent() had to grow an adoption path
        // for; the cure is the same: find the one we already own and take it
        // back, then PATCH the key onto it.
        if ($response->status() === 409) {
            $adopted = $this->findCredentialByName($payload['name']);

            if ($adopted !== null) {
                Log::info('FlowAssistantService: adopted the existing hub credential', [
                    'hub_credential_id' => $adopted,
                ]);

                Http::withHeaders($this->headers())
                    ->patch("{$this->baseUrl}/provider-credentials/{$adopted}", $payload);

                return $adopted;
            }
        }

        $this->ensureSuccessful($response, 'create the flow assistant credential');

        $id = $response->json('id');

        if (! $id) {
            throw UpstreamError::exception(
                UpstreamProvider::AiHub,
                'The hub did not return an id for the flow assistant credential.',
                upstreamCode: 'credential_id_missing',
            );
        }

        return (string) $id;
    }

    /**
     * The platform assistant agent at the hub, created or updated.
     *
     * The agent carries the system prompt — which is built from FlowBlueprint
     * and therefore changes whenever the flow format does. See
     * {@see syncPromptIfStale()} for how a deploy repairs that without anyone
     * revisiting the Back Office.
     */
    private function ensureAgent(string $credentialId, string $model): string
    {
        $externalId = FlowAssistantConfig::AGENT_EXTERNAL_ID;

        $payload = [
            'externalId' => $externalId,
            'name' => 'Flow assistant',
            'description' => 'Builds and edits Nuvemchat flows from a description. Platform-owned.',
            'providerCredentialId' => $credentialId,
            'model' => $model,
            'systemPrompt' => $this->systemPrompt(),
            // Low but not zero. The output is a strict envelope, so there is
            // nothing to gain from sampling — but the prose half is written to
            // a person, and at 0 it reads like a form letter.
            'temperature' => 0.2,
            'maxTokens' => 8000,
            'metadata' => ['ownerType' => 'platform', 'usage' => ['flow_assistant']],
        ];

        $hubAgentId = FlowAssistantConfig::hubAgentId();

        if ($hubAgentId) {
            $response = Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/agents/{$hubAgentId}", $payload);

            if ($response->successful()) {
                // The hub's value again, for the reason on the create path
                // below — re-provisioning must not overwrite a normalised
                // external id with the raw constant we sent.
                return (string) ($response->json('externalId') ?: $externalId);
            }

            Log::warning('FlowAssistantService: stored hub agent did not update, re-creating', [
                'hub_agent_id' => $hubAgentId,
                'status' => $response->status(),
            ]);
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/agents", $payload);

        // Same dead end as the credential above: `externalId` is unique at the
        // hub, so an agent that exists without us holding its id can never be
        // created again and never be updated.
        if ($response->status() === 409) {
            $adopted = $this->findAgentByExternalId($externalId);

            if ($adopted !== null) {
                Log::info('FlowAssistantService: adopted the existing hub agent', $adopted);

                Setting::set(FlowAssistantConfig::KEY_HUB_AGENT_ID, $adopted['id']);

                Http::withHeaders($this->headers())
                    ->patch("{$this->baseUrl}/agents/{$adopted['id']}", $payload);

                return $adopted['externalId'];
            }
        }

        $this->ensureSuccessful($response, 'create the flow assistant agent');

        Setting::set(FlowAssistantConfig::KEY_HUB_AGENT_ID, (string) $response->json('id'));

        // ⚠️ The hub's own value, not ours: it is what `POST /runs` matches on,
        // and if the hub normalises or prefixes what it was given, the string we
        // sent is not the string that will resolve.
        return (string) ($response->json('externalId') ?: $externalId);
    }

    /**
     * The hub id of the credential we already own under this name, if the hub
     * has one. Null when it does not — in which case the 409 was about
     * something else and should surface normally.
     *
     * A list rather than a GET by id, for the reason `ensureCredentialOnHub()`
     * gives: we are looking for a thing whose id we do not have, and a 404 from
     * a by-id route cannot be told apart from that route not existing.
     */
    private function findCredentialByName(string $name): ?string
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->get("{$this->baseUrl}/provider-credentials");

            if (! $response->successful()) {
                return null;
            }

            foreach ($this->rows($response->json()) as $row) {
                if (($row['name'] ?? null) === $name && ! empty($row['id'])) {
                    return (string) $row['id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FlowAssistantService: could not list hub credentials to adopt one', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** @return array{id: string, externalId: string}|null */
    private function findAgentByExternalId(string $externalId): ?array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->get("{$this->baseUrl}/agents");

            if (! $response->successful()) {
                return null;
            }

            foreach ($this->rows($response->json()) as $row) {
                // Endswith rather than equals: the hub may have namespaced what
                // it was given, and the id we are hunting for is the one it
                // stored, not the one we sent.
                $candidate = (string) ($row['externalId'] ?? '');

                if ($candidate !== '' && str_ends_with($candidate, $externalId) && ! empty($row['id'])) {
                    return ['id' => (string) $row['id'], 'externalId' => $candidate];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FlowAssistantService: could not list hub agents to adopt one', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * The rows out of a hub list response, which comes back either as a bare
     * array or wrapped in `data` depending on the endpoint.
     *
     * @return list<array>
     */
    private function rows(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $rows = array_is_list($body) ? $body : ($body['data'] ?? $body['items'] ?? []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Re-push the system prompt when the flow format has moved on since it was
     * last sent.
     *
     * The alternative is an assistant that keeps describing the format it was
     * provisioned against — writing nodes with fields that no longer exist,
     * failing validation every time, with nothing on any screen saying why.
     * The check is a string comparison against a stored hash; the PATCH costs
     * one call, once per deploy that changes the spec.
     */
    private function syncPromptIfStale(): void
    {
        $hash = $this->promptHash();

        if (FlowAssistantConfig::promptHash() === $hash) {
            return;
        }

        $hubAgentId = FlowAssistantConfig::hubAgentId();

        if (! $hubAgentId) {
            return;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/agents/{$hubAgentId}", [
                    'systemPrompt' => $this->systemPrompt(),
                    'model' => FlowAssistantConfig::model(),
                ]);

            if ($response->successful()) {
                Setting::set(FlowAssistantConfig::KEY_PROMPT_HASH, $hash);

                Log::info('FlowAssistantService: refreshed the assistant prompt after a format change', [
                    'hub_agent_id' => $hubAgentId,
                ]);
            }
        } catch (\Throwable $e) {
            // The old prompt still mostly works, and the customer is waiting
            // on an answer — this is not the moment to fail their turn over
            // housekeeping. It retries on the next one.
            Log::warning('FlowAssistantService: could not refresh the assistant prompt', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function promptHash(): string
    {
        return substr(hash('sha256', $this->systemPrompt()), 0, 32);
    }

    // ─────────────────────────────── Runs ───────────────────────────────

    /**
     * Answer one turn.
     *
     * @param  string  $message   what the person asked
     * @param  array   $context   ['flow' => ?array, 'tags' => list, 'agents' => list,
     *                            'ai_agents' => list, 'channels' => list<string>]
     * @param  array   $history   prior turns as [['role' => 'user'|'assistant', 'content' => string]]
     * @param  ?callable $emit    fn (string $event, array $data) — progress, for the SSE stream
     * @return array{reply: string, flow: ?array, warnings: list<string>}
     */
    public function ask(string $message, array $context, array $history = [], ?callable $emit = null): array
    {
        $emit ??= fn () => null;

        if (! FlowAssistantConfig::ready()) {
            throw UpstreamError::exception(
                UpstreamProvider::AiHub,
                'The flow assistant is not configured.',
                status: 503,
                upstreamCode: 'flow_assistant_unconfigured',
            );
        }

        $this->syncPromptIfStale();

        $emit('status', ['stage' => 'thinking']);

        $answer = $this->runTurn($this->userMessage($message, $context, $history));
        $blueprint = $answer['flow'];
        $warnings = [];

        if ($blueprint === null) {
            $emit('status', ['stage' => 'done']);

            return ['reply' => $answer['reply'], 'flow' => null, 'warnings' => []];
        }

        // Everything below is the promise in the class docblock being kept.
        for ($attempt = 0; $attempt <= FlowAssistantConfig::MAX_REPAIRS; $attempt++) {
            $emit('status', ['stage' => 'validating']);

            $problems = $this->problemsWith($blueprint);

            if ($problems === []) {
                $emit('status', ['stage' => 'done']);

                return [
                    'reply' => $answer['reply'],
                    'flow' => $this->normalize($blueprint),
                    'warnings' => $warnings,
                ];
            }

            if ($attempt === FlowAssistantConfig::MAX_REPAIRS) {
                break;
            }

            Log::info('FlowAssistantService: repairing an invalid blueprint', [
                'attempt' => $attempt + 1,
                'problems' => $problems,
            ]);

            $emit('status', ['stage' => 'repairing', 'attempt' => $attempt + 1]);

            $answer = $this->runTurn($this->repairMessage($blueprint, $problems, $message));
            $warnings[] = 'Corrigi ' . count($problems) . ' problema(s) na primeira versão.';

            if ($answer['flow'] === null) {
                // The model answered the repair with prose instead of a flow.
                // Its words are the useful thing here — usually "I cannot do
                // that with the tags this workspace has" — so they go back
                // rather than a generic failure.
                return ['reply' => $answer['reply'], 'flow' => null, 'warnings' => []];
            }

            $blueprint = $answer['flow'];
        }

        Log::warning('FlowAssistantService: gave up on a blueprint that would not validate', [
            'problems' => $this->problemsWith($blueprint),
        ]);

        $emit('status', ['stage' => 'done']);

        // Prose and no flow. Handing over a blueprint that fails validation
        // would put a canvas full of nodes in front of someone and then refuse
        // to save it — see the class docblock.
        return [
            'reply' => $answer['reply'] . "\n\n" . $this->giveUpNote($this->problemsWith($blueprint)),
            'flow' => null,
            'warnings' => [],
        ];
    }

    /**
     * One hub run: post the message, read the envelope back out.
     *
     * @return array{reply: string, flow: ?array}
     */
    private function runTurn(string $content): array
    {
        // A fresh conversation id per turn — see "Turns are stateless" above.
        $conversationId = 'flow-assistant-' . bin2hex(random_bytes(8));

        $payload = [
            'agentExternalId' => FlowAssistantConfig::agentExternalId(),
            'responseMode' => 'sync',
            'conversation' => [
                'externalId' => $conversationId,
                // ⚠️ There is no real conversation here, so this field is pure
                // ceremony for the hub's DTO — which means the only thing that
                // matters about the value is that the hub accepts it. It was
                // 'live_chat_widget', which this application can technically
                // send but which no workspace here has ever exercised (nobody
                // runs an AI agent on the widget), so it was an unproven enum
                // value chosen for tidiness. 'whatsapp' is the one every
                // working agent run in this deployment already sends. The hub
                // rejects a whole run over one unrecognised field, so guessing
                // costs the entire feature.
                'channel' => 'whatsapp',
                'contactExternalId' => $conversationId,
                'contactName' => 'Flow builder',
            ],
            'message' => [
                'role' => 'USER',
                'content' => $content,
            ],
        ];

        $response = Http::timeout(120)
            ->withHeaders($this->headers())
            ->post("{$this->baseUrl}/runs", $payload);

        $this->ensureSuccessful($response, 'run the flow assistant');

        $data = $response->json() ?? [];

        // ⚠️ A 200 can still carry a failed run — the hub answers with
        // `status: FAILED` and `output: null` when a stage throws inside it.
        // Nothing above notices, because nothing threw. Same trap
        // AiAgentHubTenantService::runAgent documents.
        if (strtoupper((string) ($data['status'] ?? '')) === 'FAILED' || ($data['error'] ?? null) !== null) {
            $error = $data['error'] ?? null;
            $error = is_array($error) ? ($error['message'] ?? json_encode($error)) : (string) $error;

            throw UpstreamError::exception(
                UpstreamProvider::AiHub,
                $error ?: 'The flow assistant run failed.',
                upstreamCode: 'run_failed',
            );
        }

        $usage = $data['output']['usage'] ?? [];

        // The platform pays for these, and nothing else records them: the
        // assistant writes no `ai_hub_runs` row, because that table is keyed
        // to a local agent belonging to a workspace and this agent belongs to
        // nobody. The log line is the whole audit trail — grep
        // 'flow assistant turn' to total it up.
        Log::info('FlowAssistantService: flow assistant turn', [
            'hub_run_id' => $data['id'] ?? null,
            'model' => $data['model'] ?? null,
            'total_tokens' => $usage['totalTokens'] ?? null,
            'cost_usd' => $data['providerCostUsd'] ?? null,
        ]);

        return $this->parseEnvelope((string) ($data['output']['message'] ?? ''));
    }

    /**
     * Pull `{reply, flow}` out of whatever the model actually wrote.
     *
     * Models wrap JSON in fences and add a sentence before it however firmly
     * the prompt says not to, so this reads the outermost braces rather than
     * trusting the whole string to parse. When there is no JSON at all, the
     * text is the reply — a question answered in prose ("what does a condition
     * node do?") is a legitimate turn, not a malformed one.
     *
     * @return array{reply: string, flow: ?array}
     */
    private function parseEnvelope(string $raw): array
    {
        $text = trim($raw);

        if ($text === '') {
            return ['reply' => 'Não consegui gerar uma resposta. Pode reformular o pedido?', 'flow' => null];
        }

        $json = $this->extractJsonObject($text);

        if ($json === null) {
            return ['reply' => $text, 'flow' => null];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return ['reply' => $text, 'flow' => null];
        }

        $reply = trim((string) ($decoded['reply'] ?? ''));
        $flow = $decoded['flow'] ?? null;

        if (! is_array($flow) || ! isset($flow['nodes']) || ! is_array($flow['nodes'])) {
            $flow = null;
        }

        return [
            'reply' => $reply !== '' ? $reply : 'Pronto.',
            'flow' => $flow,
        ];
    }

    /** The outermost {...} in a string, or null. */
    private function extractJsonObject(string $text): ?string
    {
        // Strip a fenced block first: inside one, the braces we want are the
        // whole content, and the fence itself can carry stray braces in a
        // language tag.
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * Everything wrong with a blueprint, as sentences a model can act on.
     *
     * The per-node rules come from the same validator saving uses, which is
     * the point — but its ValidationException carries keys like
     * `nodes.3.data.variable_key`, and a model repairs what it can read. So the
     * exception is unwrapped into "node #4 (response): The variable key field
     * is required."
     *
     * @return list<string>
     */
    private function problemsWith(array $blueprint): array
    {
        $problems = [];

        $nodes = array_values(array_filter((array) ($blueprint['nodes'] ?? []), 'is_array'));
        $edges = array_values(array_filter((array) ($blueprint['edges'] ?? []), 'is_array'));

        if ($nodes === []) {
            return ['The flow has no nodes.'];
        }

        if (count($nodes) > self::MAX_NODES) {
            return ['The flow has ' . count($nodes) . ' nodes; the maximum is ' . self::MAX_NODES . '. Build a smaller flow, or split it.'];
        }

        foreach ($nodes as $index => $node) {
            $type = (string) ($node['type'] ?? '');

            if (! in_array($type, FlowBlueprint::NODE_TYPES, true)) {
                $problems[] = "Node #{$index} has type \"{$type}\", which is not a node type. Allowed: " . implode(', ', FlowBlueprint::NODE_TYPES) . '.';
            }
        }

        // Bail before the per-type rules: they match on `type`, so an unknown
        // one produces no rules at all and would pass silently.
        if ($problems !== []) {
            return $problems;
        }

        try {
            FlowBlueprint::validateNodes($nodes);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $index = (int) (explode('.', $key)[1] ?? 0);
                $nodeKey = (string) ($nodes[$index]['key'] ?? $index);
                $nodeType = (string) ($nodes[$index]['type'] ?? '?');

                foreach ((array) $messages as $messageText) {
                    $field = implode('.', array_slice(explode('.', $key), 3));
                    $field = $field !== '' ? " (field \"{$field}\")" : '';

                    $problems[] = "Node \"{$nodeKey}\" ({$nodeType}){$field}: {$messageText}";
                }
            }
        }

        return array_values(array_unique(array_merge(
            $problems,
            FlowBlueprint::structureProblems($nodes, $edges),
        )));
    }

    /**
     * Fill in what the builder needs and the model is careless about:
     * positions, string keys, a name.
     *
     * Positions especially. They are not validated — the save endpoint takes
     * any number — so a model that omits them, or reuses one, produces a flow
     * that saves perfectly and draws as a pile of nodes on one spot, which
     * reads as nodes that failed to appear. {@see FlowLayout} is what makes
     * room; everything here is shape.
     */
    private function normalize(array $blueprint): array
    {
        $nodes = array_values(array_filter((array) ($blueprint['nodes'] ?? []), 'is_array'));
        $edges = array_values(array_filter((array) ($blueprint['edges'] ?? []), 'is_array'));

        foreach ($nodes as $index => $node) {
            $nodes[$index]['key'] = (string) ($node['key'] ?? $index);
            $nodes[$index]['data'] = ($node['type'] ?? '') === 'start' ? null : ($node['data'] ?? []);
        }

        foreach ($edges as $index => $edge) {
            $edges[$index] = [
                'source_key' => (string) ($edge['source_key'] ?? ''),
                'target_key' => (string) ($edge['target_key'] ?? ''),
                'condition_value' => isset($edge['condition_value']) && $edge['condition_value'] !== ''
                    ? (string) $edge['condition_value']
                    : null,
            ];
        }

        return [
            'name' => trim((string) ($blueprint['name'] ?? '')) ?: 'Fluxo gerado por IA',
            // Last, and after the edges are cleaned: the layout is derived from
            // the graph, so it needs the connections to be readable first.
            'nodes' => FlowLayout::resolve($nodes, $edges),
            'edges' => $edges,
        ];
    }

    // ────────────────────────────  Prompts  ─────────────────────────────

    /**
     * The system prompt, pushed to the hub agent. Shared by every workspace —
     * which is why nothing workspace-specific may go in it; the tenant's tags,
     * agents and current flow ride in each message instead.
     */
    private function systemPrompt(): string
    {
        $spec = FlowBlueprint::specification();

        return <<<PROMPT
        You are the flow assistant inside Nuvemchat, an omnichannel customer-messaging
        platform. You help a business build and edit automation flows: the graph that
        greets customers, asks questions, branches, and decides when a human takes over.

        You speak to a business owner, not an engineer. Never mention JSON, nodes,
        edges, keys or schemas in your `reply` — describe what the flow will DO, step
        by step, in their language. Write in Brazilian Portuguese unless the person
        writes to you in another language, and then use theirs.

        {$spec}

        # How you answer

        Reply with ONE JSON object and nothing else — no prose outside it, no code
        fence:

        {
          "reply": "What you are telling the person, in their language.",
          "flow": { "name": "...", "nodes": [...], "edges": [...] }
        }

        - `flow` is the COMPLETE flow after your change — never a patch, never only
          the new nodes. When the person is editing an existing flow, start from the
          one given to you in the context, keep the keys of the nodes you are not
          changing, and return the whole thing.
        - Set `flow` to null when there is nothing to build: a question about how
          something works, a request you need clarified, or something the platform
          cannot do. Then say so in `reply`.
        - Only use ids (tags, agents, AI agents, payment and pixel integrations,
          other flows) that appear in the context below. Never invent one. If what
          the person asked for needs a tag, an agent or an integration that does not
          exist, build the rest and say in `reply` which piece is missing and where
          to create it.
        - Keep `reply` short: a sentence on what the flow does, then the steps as a
          brief list. The person is looking at the flow you drew — do not narrate it
          twice.

        # Judgement

        - Prefer few nodes that work over many that impress. A first flow is usually
          a greeting, one question, two or three branches, and a handoff to a human.
        - Every branch must lead somewhere. An unwired branch is a customer left in
          silence, which is worse than no automation at all.
        - End every path: the conversation is closed (status), a person takes it over
          (action / transfer_human), or another flow continues it (go_to_flow). A
          flow that just stops leaves the thread in limbo.
        - When the request is genuinely ambiguous, ask ONE question in `reply` with
          `flow` set to null. Do not ask two, and do not ask when a sensible default
          exists — build it and say what you assumed.
        PROMPT;
    }

    /** The per-turn message: context, transcript, request. */
    private function userMessage(string $message, array $context, array $history): string
    {
        $sections = [];

        $sections[] = "# Workspace context\n" . json_encode([
            'tags' => $context['tags'] ?? [],
            'agents' => $context['agents'] ?? [],
            'ai_agents' => $context['ai_agents'] ?? [],
            'payment_integrations' => $context['payment_integrations'] ?? [],
            'pixel_integrations' => $context['pixel_integrations'] ?? [],
            'flows' => $context['flows'] ?? [],
            'channels' => $context['channels'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // Media the person picked from their library for this request. Listed
        // as its own section rather than buried in the context blob because it
        // is an instruction, not background: these URLs are the only ones the
        // model may put in a node, and it has no other way to obtain a valid
        // one — a made-up URL saves fine and then fails at send time, months
        // later, in front of a customer.
        $gallery = (array) ($context['gallery'] ?? []);

        if ($gallery !== []) {
            $sections[] = "# Media the person attached (from their library)\n"
                . json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . "\n\n"
                . 'Use these EXACT `url` values — copy them character for character — as '
                . '`attachment_url` on a message/response bubble (with the matching '
                . '`message_type`), or as `header_url` on a carousel card. Never invent a '
                . 'media URL and never alter one of these. Use each file where the person '
                . 'asked for it; if they did not say, put it on the step it obviously '
                . 'belongs to and say in `reply` where you placed it.';
        }

        // Whether the interactive node is even available is a channel question,
        // and the model cannot infer it — a flow wired to Telegram that answers
        // with WhatsApp buttons saves and then never renders.
        $channels = (array) ($context['channels'] ?? []);
        if ($channels !== [] && ! in_array('whatsapp_official', $channels, true)) {
            $sections[] = '# Channel restriction' . "\n"
                . 'This flow is linked to connections that are NOT WhatsApp Official ('
                . implode(', ', $channels) . '). Do NOT use interactive nodes — they '
                . 'would be rejected. Offer choices as a message with numbered options '
                . 'followed by a response node.';
        }

        $flow = $context['flow'] ?? null;

        if (is_array($flow) && ($flow['nodes'] ?? []) !== []) {
            $sections[] = "# The flow as it stands right now\n"
                . json_encode($flow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $sections[] = '# The flow as it stands right now' . "\n"
                . 'Empty — only the start node. You are building it from scratch.';
        }

        if ($history !== []) {
            $lines = [];
            foreach ($history as $turn) {
                $role = ($turn['role'] ?? '') === 'assistant' ? 'You' : 'Person';
                $lines[] = $role . ': ' . mb_substr(trim((string) ($turn['content'] ?? '')), 0, 800);
            }
            $sections[] = "# Earlier in this conversation\n" . implode("\n", $lines);
        }

        $sections[] = "# What the person is asking for now\n" . $message;

        return implode("\n\n", $sections);
    }

    /** @param list<string> $problems */
    private function repairMessage(array $blueprint, array $problems, string $original): string
    {
        $list = implode("\n", array_map(fn ($p) => "- {$p}", $problems));

        return <<<REPAIR
        The flow you just produced does not pass validation and cannot be saved.

        # The original request
        {$original}

        # The flow you produced
        {$this->encode($blueprint)}

        # What is wrong with it
        {$list}

        Fix exactly these problems and return the COMPLETE corrected flow in the same
        JSON envelope. Change nothing else. If a problem cannot be fixed — a tag or an
        agent that does not exist in this workspace, for instance — remove the node
        that depends on it, rewire the flow around the gap, and say so in `reply`.
        REPAIR;
    }

    /** @param list<string> $problems */
    private function giveUpNote(array $problems): string
    {
        $shown = array_slice($problems, 0, 3);

        return "⚠️ Não consegui montar um fluxo válido para esse pedido, então não apliquei nada no editor. "
            . "O que travou: \n- " . implode("\n- ", $shown);
    }

    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ─────────────────────────────  Plumbing  ───────────────────────────

    /**
     * Auth for every hub call — the platform's own tenant token, the same one
     * behind every other hub request this application makes.
     */
    private function headers(): array
    {
        $token = AiAgentHubConfig::tenantToken();

        if (! $token) {
            Log::error('No AI Agent Hub tenant token configured. Set it in Back Office → Integrations → AI Hub.');

            throw UpstreamError::exception(
                UpstreamProvider::AiHub,
                'AI hub tenant token is not configured.',
                status: 503,
            );
        }

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Same failure handling the workspace-facing hub client uses.
     *
     * ⚠️ This used to hand `$response->body()` — the raw JSON string — to
     * UpstreamError, and that guaranteed the least useful outcome available: a
     * hub 400 reads `{"message":["property x should not exist"],...}`, which
     * matches nothing in the dictionary, so *every* failure of this feature came
     * out as the generic "O serviço de IA está indisponível", with the actual
     * reason visible nowhere a person would look. The hub answers `message` in
     * three different shapes (string, list, per-field object) and
     * {@see AiAgentHubTenantService::hubMessage()} already knows all three —
     * writing a second parser here is the same mistake as writing a second copy
     * of the flow rules.
     */
    private function ensureSuccessful(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = AiAgentHubTenantService::hubMessage($response, "Failed to {$action}");

        // The hub's own words go to the log and the `ref`, never to the
        // customer — see App\Support\Errors\UpstreamError. Worded like the
        // tenant client's line so one grep finds hub validation failures
        // whichever surface provoked them.
        Log::error("FlowAssistantService: Validation failed to {$action}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw UpstreamError::exception(
            UpstreamProvider::AiHub,
            $message,
            status: $response->status(),
            context: ['action' => $action],
        );
    }
}
