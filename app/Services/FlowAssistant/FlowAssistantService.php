<?php

namespace App\Services\FlowAssistant;

use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UserFacingException;
use App\Models\AiHubProviderCredential;
use App\Models\FlowAssistantSetting;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Credits\CreditService;
use App\Services\Flow\FlowBlueprint;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * ── Whose key it runs on ──
 *
 * The workspace's. It used to be one OpenAI key the platform pasted into the
 * Back Office, paying for every turn of every workspace; now each workspace
 * picks its own provider key or rents one from the platform, exactly as it
 * does for an AI agent, and a rented key is billed to the prepaid balance run
 * by run. Each workspace therefore gets its own agent at the hub — the agent is
 * what carries the credential and the model — recorded in
 * {@see FlowAssistantSetting} rather than in `ai_hub_agents`, so it never shows
 * up on the AI Agents page where its prompt could be edited away.
 *
 * ── Why this does not go through AiAgentHubTenantService::runAgent ──
 *
 * That method exists to answer a customer, and everything it does around the
 * HTTP call says so: it needs a real Conversation and writes an `ai_hub_runs`
 * row keyed to a local agent. Neither is true here. What the two share is the
 * one thing that matters — a rented key is gated on the balance before the run
 * and charged after it, through the same wallet.
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

    /** The code the builder switches into its key setup on. */
    public const SETUP_REQUIRED = 'flow_assistant_setup_required';

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = AiAgentHubConfig::baseUrl();
    }

    // ────────────────────────────── Setup ───────────────────────────────

    /**
     * Whether the assistant can run at all on this deployment.
     *
     * Only the platform's hub token is ours to provide now; the key is the
     * workspace's. Without the token there is nothing a customer can do about
     * it, so the builder hides the button rather than asking for a key that
     * would not help.
     */
    public static function available(): bool
    {
        return AiAgentHubConfig::tenantToken() !== null;
    }

    public function settingFor(Tenant $tenant): ?FlowAssistantSetting
    {
        return FlowAssistantSetting::with('providerCredential')
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    /**
     * Put the workspace's assistant on a key and a model.
     *
     * Creates the workspace's agent at the hub the first time and updates it
     * after that, so choosing again is the same action as choosing first. The
     * agent is written before the row is saved: a choice the hub refused is not
     * a choice the builder may report as made.
     */
    public function configure(Tenant $tenant, AiHubProviderCredential $credential, string $model): FlowAssistantSetting
    {
        return Cache::lock("flow-assistant:configure:{$tenant->id}", 30)->block(10, function () use ($tenant, $credential, $model) {
            $setting = FlowAssistantSetting::firstOrNew(['tenant_id' => $tenant->id]);
            $setting->ai_hub_provider_credential_id = $credential->id;
            $setting->model = $model;
            $setting->setRelation('providerCredential', $credential);

            $this->pushAgent($setting);
            $setting->save();

            return $setting->fresh('providerCredential');
        });
    }

    /**
     * Move every assistant on one credential onto another — a rented key that
     * was rotated or re-minted.
     *
     * Local only, on purpose: it runs inside a rotation that may be moving
     * dozens of workspaces, and the agent's fingerprint changes with the
     * credential, so each assistant re-points itself at the hub on its next
     * turn ({@see syncAgent()}). Nothing runs on the old credential in between,
     * because nothing runs without a turn.
     */
    public static function repoint(AiHubProviderCredential $old, AiHubProviderCredential $new): void
    {
        FlowAssistantSetting::where('ai_hub_provider_credential_id', $old->id)
            ->update(['ai_hub_provider_credential_id' => $new->id]);
    }

    /**
     * The workspace's setting, refusing the turn when there is nothing to run
     * it on — or nothing to pay for it with.
     *
     * Public so the builder's endpoints can call it before anything is
     * written or streamed: inside an SSE response the status code is already
     * sent, and "set up a key first" has to arrive as a 409 the panel can
     * switch on, not as a sentence in an error event.
     *
     * @throws UserFacingException when no key is set up
     * @throws CreditExhaustedException when a rented key has an empty wallet behind it
     */
    public function assertReady(Tenant $tenant): FlowAssistantSetting
    {
        $setting = $this->configuredSetting($tenant);

        // Only rented keys are gated, and only behind the same master switch as
        // every other AI run: a workspace on its own key pays its provider, not
        // us, and stopping it here would be inventing a limit nobody sold.
        if ($setting->isRented() && config('services.billing.enforce')) {
            $credits = app(CreditService::class);

            if (! $credits->canSpend($tenant)) {
                throw new CreditExhaustedException($credits->balanceCents($tenant), $credits->currencyFor($tenant));
            }
        }

        return $setting;
    }

    private function configuredSetting(Tenant $tenant): FlowAssistantSetting
    {
        $setting = $this->settingFor($tenant);

        if ($setting === null || ! $setting->isConfigured() || $setting->providerCredential === null) {
            throw new UserFacingException(
                'Choose which AI key the assistant should use before asking it anything.',
                409,
                self::SETUP_REQUIRED,
            );
        }

        return $setting;
    }

    /**
     * Bring the hub agent up to date when anything it carries has moved on.
     *
     * The prompt is built from FlowBlueprint, so it changes whenever the flow
     * format does — and a deploy that adds a node type must not leave the
     * assistant explaining the old format. The credential changes when a rented
     * key is rotated. Hashing all three makes every one of those repairs the
     * same string comparison, paid for with one PATCH, once.
     */
    private function syncAgent(FlowAssistantSetting $setting): void
    {
        if ($setting->agent_hash === $this->fingerprint($setting)) {
            return;
        }

        try {
            $this->pushAgent($setting);
            $setting->save();

            Log::info('FlowAssistantService: refreshed the workspace assistant agent', [
                'tenant_id' => $setting->tenant_id,
                'hub_agent_id' => $setting->hub_agent_id,
            ]);
        } catch (\Throwable $e) {
            // The old agent still mostly works, and the person is waiting on an
            // answer — this is not the moment to fail their turn over
            // housekeeping. It retries on the next one.
            Log::warning('FlowAssistantService: could not refresh the workspace assistant agent', [
                'tenant_id' => $setting->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create or update the workspace's assistant agent at the hub, and record
     * what was pushed on the (unsaved) setting.
     *
     * Only a 404 falls through to creating a new one: anything else the hub
     * refuses — a disabled credential, a model it does not carry — is the
     * answer to the choice just made, and has to reach the person making it.
     */
    private function pushAgent(FlowAssistantSetting $setting): void
    {
        $credential = $setting->providerCredential;
        $externalId = $this->agentExternalId($credential);

        $payload = [
            'externalId' => $externalId,
            'name' => 'Flow assistant',
            'description' => 'Builds and edits flows from a description. Managed by the platform.',
            // ⚠️ The hub's id, never the local one and never the key itself.
            'providerCredentialId' => $credential->hub_provider_credential_id,
            'model' => $setting->model,
            'systemPrompt' => $this->systemPrompt(),
            // Low but not zero. The output is a strict envelope, so there is
            // nothing to gain from sampling — but the prose half is written to
            // a person, and at 0 it reads like a form letter.
            'temperature' => 0.2,
            'maxTokens' => 8000,
            'metadata' => ['ownerType' => 'platform', 'usage' => ['flow_assistant'], 'workspace' => $setting->tenant_id],
        ];

        if ($setting->hub_agent_id) {
            $response = Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/agents/{$setting->hub_agent_id}", $payload);

            if ($response->status() !== 404) {
                $this->ensureSuccessful($response, 'update the flow assistant agent');

                $this->recordAgent($setting, (string) $setting->hub_agent_id, $response->json('externalId') ?: $externalId);

                return;
            }

            Log::warning('FlowAssistantService: the hub lost this workspace assistant agent, re-creating it', [
                'tenant_id' => $setting->tenant_id,
                'hub_agent_id' => $setting->hub_agent_id,
            ]);
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/agents", $payload);

        // `externalId` is unique at the hub, so an agent that exists without us
        // holding its id could never be created again nor updated — the same
        // dead end AiTokenRentalService::rent() had to grow an adoption path
        // for. Find the one that is ours and take it back.
        if ($response->status() === 409) {
            $adopted = $this->findAgentByExternalId($externalId);

            if ($adopted !== null) {
                Log::info('FlowAssistantService: adopted the existing workspace assistant agent', $adopted);

                $patched = Http::withHeaders($this->headers())
                    ->patch("{$this->baseUrl}/agents/{$adopted['id']}", $payload);

                $this->ensureSuccessful($patched, 'update the flow assistant agent');
                $this->recordAgent($setting, $adopted['id'], $adopted['externalId']);

                return;
            }
        }

        $this->ensureSuccessful($response, 'create the flow assistant agent');

        // ⚠️ The hub's own external id, not ours: it is what `POST /runs`
        // matches on, and if the hub normalises what it was given, the string
        // we sent is not the string that will resolve.
        $this->recordAgent($setting, (string) $response->json('id'), $response->json('externalId') ?: $externalId);
    }

    private function recordAgent(FlowAssistantSetting $setting, string $hubAgentId, string $externalId): void
    {
        $setting->hub_agent_id = $hubAgentId;
        $setting->agent_external_id = $externalId;
        $setting->agent_hash = $this->fingerprint($setting);
    }

    /**
     * Scoped like every other agent we create — `{app}_{scope}_{id}` — because
     * every workspace's agents live in the platform's one hub scope, and two
     * workspaces must never reach for the same id.
     */
    private function agentExternalId(AiHubProviderCredential $credential): string
    {
        return config('app.name').'_'.$credential->ai_hub_tenant_id.'_'.FlowAssistantConfig::AGENT_EXTERNAL_ID;
    }

    private function fingerprint(FlowAssistantSetting $setting): string
    {
        return substr(hash('sha256', implode("\n", [
            $this->systemPrompt(),
            (string) $setting->providerCredential?->hub_provider_credential_id,
            (string) $setting->model,
        ])), 0, 32);
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
                // it was given. The id is fully scoped already, so no other
                // workspace's agent can end with it.
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
    public function ask(Tenant $tenant, string $message, array $context, array $history = [], ?callable $emit = null): array
    {
        $emit ??= fn () => null;

        // Checked once per turn, not per run: a turn that has started is
        // finished even if its repairs take the balance below zero — refusing
        // the repair would throw away the answer that was already paid for.
        $setting = $this->assertReady($tenant);

        $this->syncAgent($setting);

        $emit('status', ['stage' => 'thinking']);

        $answer = $this->runTurn($setting, $this->userMessage($message, $context, $history));
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

            $answer = $this->runTurn($setting, $this->repairMessage($blueprint, $problems, $message));
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
     * Translate a batch of strings into one language.
     *
     * The same hub agent as the builder, asked for something narrower: it is
     * handed strings, never the graph, so nothing it writes can move a branch
     * or drop a node. What comes back is keyed by the ids that went in; an id
     * the model left out is simply absent, and the caller keeps the original.
     *
     * The balance is not re-checked here: a translation is several batches,
     * and stopping halfway would throw away the ones already paid for. The
     * caller checks once, before the first ({@see assertReady()}).
     *
     * @param  list<array{id: string, text: string, max?: int|null}>  $strings
     * @return array<string, string>
     */
    public function translate(Tenant $tenant, array $strings, string $language): array
    {
        $setting = $this->configuredSetting($tenant);

        $this->syncAgent($setting);

        $items = $this->encode(array_map(fn (array $string) => array_filter([
            'id' => $string['id'],
            'max' => $string['max'] ?? null,
            'text' => $string['text'],
        ], fn ($value) => $value !== null), $strings));

        $content = <<<PROMPT
        This is a TRANSLATION task, not a flow-building task. Do not build or
        describe a flow.

        Translate each string below into {$language}. They are messages a
        business sends to its customers on WhatsApp and similar apps.

        Rules:
        - Write the way a native speaker of {$language} would write to a
          customer: natural and in the same tone, not word for word.
        - Keep every {{placeholder}} exactly as written — same name, same braces.
        - Keep emojis, line breaks, URLs, numbers, prices, brand and product
          names, and formatting marks such as *bold* and _italic_.
        - When a string has "max", the translation must not be longer than that
          many characters. Shorten it naturally rather than cutting it off.
        - A string already in {$language} is returned unchanged.
        - Return every id you were given, and no others.

        Reply with ONE JSON object and nothing else:

        {"reply": "ok", "flow": null, "translations": {"<id>": "<translated text>"}}

        Strings:
        {$items}
        PROMPT;

        $json = $this->extractJsonObject($this->runRaw($setting, $content));
        $decoded = $json !== null ? json_decode($json, true) : null;
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (! is_array($translations)) {
            throw UpstreamError::exception(
                UpstreamProvider::AiHub,
                'The flow assistant returned no translations.',
                upstreamCode: 'run_failed',
            );
        }

        return array_map('strval', array_filter($translations, 'is_string'));
    }

    /**
     * One hub run: post the message, read the envelope back out.
     *
     * @return array{reply: string, flow: ?array}
     */
    private function runTurn(FlowAssistantSetting $setting, string $content): array
    {
        return $this->parseEnvelope($this->runRaw($setting, $content));
    }

    /** One hub run, returning exactly what the model wrote. */
    private function runRaw(FlowAssistantSetting $setting, string $content, bool $healed = false): string
    {
        // A fresh conversation id per turn — see "Turns are stateless" above.
        $conversationId = 'flow-assistant-' . bin2hex(random_bytes(8));

        $payload = [
            'agentExternalId' => $setting->agent_external_id,
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
            'metadata' => ['purpose' => 'flow_assistant'],
        ];

        $response = Http::timeout(120)
            ->withHeaders($this->headers())
            ->post("{$this->baseUrl}/runs", $payload);

        // The hub no longer knows this workspace's agent (rebuilt, or deleted on
        // their side). Everything needed to put it back is here, and the person
        // waiting knows a flow, not an agent id — so it is rebuilt and the run
        // tried once more, silently.
        if ($response->status() === 404 && ! $healed) {
            Log::warning('FlowAssistantService: the hub does not know this workspace assistant agent, rebuilding it', [
                'tenant_id' => $setting->tenant_id,
                'agent_external_id' => $setting->agent_external_id,
            ]);

            $setting->hub_agent_id = null;
            $this->pushAgent($setting);
            $setting->save();

            return $this->runRaw($setting, $content, healed: true);
        }

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
        $costUsd = $data['providerCostUsd'] ?? ($data['output']['cost']['usd'] ?? null);

        Log::info('FlowAssistantService: flow assistant turn', [
            'tenant_id' => $setting->tenant_id,
            'hub_run_id' => $data['id'] ?? null,
            'model' => $data['model'] ?? null,
            'rented' => $setting->isRented(),
            'total_tokens' => $usage['totalTokens'] ?? null,
            'cost_usd' => $costUsd,
        ]);

        $this->chargeIfRented($setting, $data, $costUsd);

        return (string) ($data['output']['message'] ?? '');
    }

    /**
     * Bill a completed run to the prepaid balance, when it ran on a rented key.
     *
     * After the run, never before: the price is the provider's own cost, and
     * the hub only reports that with the result. Failures are swallowed for the
     * reason AiAgentHubTenantService::chargeRentedRun gives — the answer has
     * been generated and paid for at the provider, and losing it over a
     * bookkeeping problem helps nobody. An unbilled run is a log line and a
     * reconcilable gap.
     *
     * @param  array<string, mixed>  $data
     */
    private function chargeIfRented(FlowAssistantSetting $setting, array $data, mixed $costUsd): void
    {
        if (! $setting->isRented()) {
            return;
        }

        try {
            $tenant = $setting->tenant;

            if ($tenant === null) {
                return;
            }

            app(CreditService::class)->chargeAssistantRun(
                $tenant,
                (string) ($data['id'] ?? Str::uuid()),
                $costUsd === null ? null : (float) $costUsd,
                $data['provider'] ?? $setting->providerCredential?->provider,
                $data['model'] ?? $setting->model,
            );
        } catch (\Throwable $e) {
            Log::error('FlowAssistantService: failed to charge a rented run to the wallet', [
                'tenant_id' => $setting->tenant_id,
                'hub_run_id' => $data['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
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
        - Only use ids (tags, agents, AI agents, payment, invoice and pixel integrations,
          other flows, lead stages) that appear in the context below. Never invent one. If what
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
            'invoice_integrations' => $context['invoice_integrations'] ?? [],
            'pixel_integrations' => $context['pixel_integrations'] ?? [],
            'flows' => $context['flows'] ?? [],
            'lead_stages' => $context['lead_stages'] ?? [],
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
