<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Integration\IntegrationCategory;
use App\Services\AiAgentHub\AiHoldingMessage;
use App\Services\Integrations\Pixels\PixelEvents;
use App\Services\Market\MarketCapabilities;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The flow file contract, in one place: what a node of each type may contain,
 * what makes a set of nodes and edges a coherent flow, and — derived from the
 * same constants — the specification handed to the AI assistant.
 *
 * The rules themselves are not new; they were inline in FlowController and are
 * moved here unchanged. What is new is the third reader. Saving, importing and
 * the assistant now validate against one definition, which is the only way the
 * assistant can promise what it promises: a blueprint it hands the builder is
 * a blueprint the save endpoint accepts. A second copy of these rules written
 * for the prompt would be right on the day it was written and quietly wrong
 * after the next node type — and the failure would land on a customer watching
 * a generated flow refuse to save, with no way to tell whose fault it was.
 *
 * That is also why {@see specification()} interpolates the constants rather
 * than spelling the values out: a new message type or a raised card ceiling
 * reaches the prompt without anyone remembering to edit prose.
 *
 * Mirrors, on the frontend:
 *   src/pages/Flow/JsonFormat.tsx   (the human-readable version of this file)
 *   src/types/flow.ts               (the same shapes in TypeScript)
 */
class FlowBlueprint
{
    /** Allowed node types. The frontend palette must stay in sync. */
    public const NODE_TYPES = [
        'start', 'message', 'response', 'wait_response', 'status', 'tagging',
        'condition', 'action', 'ai_agent', 'http_request', 'interactive',
        'payment', 'invoice', 'pixel', 'go_to_flow', 'lead',
    ];

    /**
     * Edge branch values: the fixed pair per branching node — condition
     * (true/false), http_request (success/error), wait_response (replied/timeout) —
     * plus an interactive node's option ids, which are authored per node and so
     * can only be pattern-checked.
     */
    public const BRANCH_VALUE_PATTERN = 'regex:/^[A-Za-z0-9_\-]{1,64}$/';

    /** Portable export envelope identifiers. */
    public const EXPORT_FORMAT = 'nuvemchat.flow';

    public const EXPORT_VERSION = 1;

    /**
     * Branch values a node type emits, where the set is fixed. Interactive
     * nodes are absent on purpose: their branches are the option ids their
     * author invented, so there is nothing to enumerate here.
     *
     * @var array<string, list<string>>
     */
    public const FIXED_BRANCHES = [
        'condition' => ['true', 'false'],
        'http_request' => ['success', 'error'],
        'wait_response' => WaitResponseNodes::BRANCHES,
        'payment' => PaymentNodes::BRANCHES,
        'invoice' => InvoiceNodes::BRANCHES,
    ];

    /**
     * Node types that end the flow and therefore have no outgoing edge.
     * `status` closes the conversation; `go_to_flow` hands it to another flow,
     * whose start node takes over. `action` is terminal for two of its three
     * types, which is a per-node question and lives in
     * {@see ActionNodes::isTerminal()}.
     */
    public const TERMINAL_NODE_TYPES = ['status', 'go_to_flow'];

    /**
     * Validation rules for a node's `data`, by node type.
     *
     * Some rules are tenant-scoped (tags, agents, AI agents must exist for the
     * caller's workspace), so this needs an authenticated user — the same
     * requirement saving and importing already had. It is also what stops the
     * assistant inventing a tag id: an invented one fails here, and the repair
     * loop is told so.
     */
    public static function rulesFor(string $type): array
    {
        return match ($type) {
            // A message node holds a list of bubbles in `messages`; the flat
            // body/message_type/attachment_url/delay are what nodes saved
            // before that list looked like, and MessageNodes::items() reads
            // whichever is there. Both are nullable for the same reason
            // http_request's url is: the builder auto-saves a node the moment
            // it lands on the canvas, and the executor skips a bubble with
            // nothing in it rather than failing the save.
            'message' => [
                'body' => ['nullable', 'string'],
                'message_type' => ['nullable', 'string', Rule::in(MessageNodes::MESSAGE_TYPES)],
                'attachment_url' => ['nullable', 'string'],
                'delay' => ['nullable', 'integer', 'min:0', 'max:'.MessageNodes::MAX_DELAY_SECONDS],
                'messages' => ['nullable', 'array', 'max:'.MessageNodes::MAX_ITEMS],
                'messages.*.body' => ['nullable', 'string'],
                'messages.*.message_type' => ['nullable', 'string', Rule::in(MessageNodes::MESSAGE_TYPES)],
                'messages.*.attachment_url' => ['nullable', 'string'],
                'messages.*.delay' => ['nullable', 'integer', 'min:0', 'max:'.MessageNodes::MAX_DELAY_SECONDS],
            ],
            'response' => [
                'body' => ['required', 'string'],
                'message_type' => ['required', 'string', Rule::in(MessageNodes::MESSAGE_TYPES)],
                'attachment_url' => ['nullable', 'string'],
                'variable_key' => ['required', 'string'],
                'validation' => ['nullable', 'string', Rule::in(WaitResponseNodes::VALIDATIONS)],
                'error_message' => ['nullable', 'string'],
            ],
            // Every field optional: with nothing set the node is the plain pause
            // the Message node's old switch was. `timeout_unit` is how the
            // builder shows the limit; the engine only reads the seconds.
            'wait_response' => [
                'message' => ['nullable', 'string', 'max:'.WaitResponseNodes::MAX_MESSAGE_LENGTH],
                'variable_key' => ['nullable', 'string', 'max:255'],
                'timeout_seconds' => ['nullable', 'integer', 'min:0', 'max:'.WaitResponseNodes::MAX_TIMEOUT_SECONDS],
                'timeout_unit' => ['nullable', 'string', Rule::in(array_keys(WaitResponseNodes::TIMEOUT_UNITS))],
                'buffer_seconds' => ['nullable', 'integer', 'min:0', 'max:'.WaitResponseNodes::MAX_BUFFER_SECONDS],
                'validation' => ['nullable', 'string', Rule::in(WaitResponseNodes::VALIDATIONS)],
                'error_message' => ['nullable', 'string', 'max:'.WaitResponseNodes::MAX_MESSAGE_LENGTH],
            ],
            // Resolved is the only status a flow may set; the reasoning is on
            // NodeType::data and FlowExecutor::executeStatusNode. Strict rather
            // than lenient because nothing in the builder can produce anything
            // else — a different value arriving here is a bug, not old data.
            'status' => [
                'value' => ['required', 'string', Rule::in([ConversationStatus::Resolved->value])],
            ],
            'tagging' => [
                'action' => ['required', 'string', Rule::in(['add', 'remove'])],
                'target' => ['nullable', 'string', Rule::in(['conversation', 'contact'])],
                'tags' => ['nullable', 'array'],
                // Tenant-scoped, like the action node's agent_id beside it.
                // This used to be a bare `exists:tags,id`, and nothing scoped it
                // at runtime either — FlowExecutor::executeTaggingNode calls
                // syncWithoutDetaching() with whatever ids the node holds — so a
                // flow could attach another workspace's tag to its own
                // conversation and put that workspace's label in this one's
                // inbox. Reachable by hand before; reachable by a model
                // guessing an id now.
                'tags.*' => [
                    'integer',
                    Rule::exists('tags', 'id')->where('tenant_id', self::tenantId()),
                ],
            ],
            'condition' => [
                'field' => ['required', 'string'],
                'operator' => ['required', 'string', Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'])],
                'value' => ['nullable', 'string'], // nullable for is_empty/is_not_empty operators
            ],
            // Nullable like http_request's url: a node is dropped on the canvas
            // and auto-saved before its author has picked anything, and the
            // executor skips one that never got configured. The parameters are
            // a flat union across the three actions rather than a per-type
            // shape — the unused keys are simply absent, and a rule that has to
            // read `type` to know whether a field is required is a rule that
            // breaks the moment auto-save catches the node mid-edit.
            'action' => [
                'type' => ['nullable', 'string', Rule::in(ActionNodes::TYPES)],
                'parameters' => ['nullable', 'array'],
                'parameters.agent_id' => [
                    'nullable',
                    'integer',
                    // Tenant-scoped here as well as at runtime: this is what
                    // stops one workspace's flow naming another workspace's user.
                    Rule::exists('users', 'id')->where('tenant_id', self::tenantId()),
                ],
                'parameters.when_unavailable' => ['nullable', 'string', Rule::in(ActionNodes::UNAVAILABLE_MODES)],
                'parameters.note' => ['nullable', 'string', 'max:4000'],
            ],
            // Both nullable, like every other node auto-save catches mid-edit.
            // They used to be required, and a single AI node without an agent
            // or a welcome made every save of the whole flow fail — edits to
            // unrelated nodes included — for as long as it stayed that way.
            // The executor covers both gaps (FlowExecutor::executeAIAgentNode):
            // no agent → the node moves on the way a handoff does; no welcome →
            // the AI answers the opening itself. Ownership stays strict: an id
            // that is there must still be an active agent of this workspace.
            // The assistant is still held to both fields, in
            // incompleteNodeProblems() — generated output has no mid-edit.
            'ai_agent' => [
                'ai_hub_agent_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('ai_hub_agents', 'id')->where(function ($query) {
                        $tenantId = self::tenantId();
                        $query->whereIn('ai_hub_tenant_id', function ($sub) use ($tenantId) {
                            $sub->select('id')
                                ->from('ai_hub_tenants')
                                ->where('tenant_id', $tenantId);
                        })->where('status', 'ACTIVE');
                    }),
                ],
                'welcoming_message' => ['nullable', 'string', 'max:4000'],
                'store_summary_to_variable' => ['nullable', 'string', 'alpha_dash'],
                // What fills the wait while the agent thinks. Absent on every
                // node built before it existed, and absent means silent — the
                // engine does not know what language the conversation is in, so
                // there is no default sentence it could safely invent.
                'holding_message' => ['nullable', 'array'],
                'holding_message.enabled' => ['nullable', 'boolean'],
                'holding_message.after_seconds' => ['nullable', 'integer', 'min:0', 'max:'.AiHoldingMessage::MAX_AFTER_SECONDS],
                'holding_message.messages' => ['nullable', 'array', 'max:'.AiHoldingMessage::MAX_LINES],
                'holding_message.messages.*' => ['nullable', 'string', 'max:'.AiHoldingMessage::MAX_LENGTH],
                'holding_message.media_messages' => ['nullable', 'array', 'max:'.AiHoldingMessage::MAX_LINES],
                'holding_message.media_messages.*' => ['nullable', 'string', 'max:'.AiHoldingMessage::MAX_LENGTH],
            ],
            // Lengths mirror the WhatsApp Cloud API limits so the builder warns
            // long before a send fails. Texts stay nullable (like http_request)
            // so auto-save never fights a half-finished node; the executor skips
            // a node with no body or no options at runtime.
            'interactive' => [
                'interactive_type' => ['required', 'string', Rule::in(InteractiveNodes::TYPES)],
                'header' => ['nullable', 'string', 'max:60'],
                'body' => ['nullable', 'string', 'max:1024'],
                'footer' => ['nullable', 'string', 'max:60'],
                'buttons' => ['nullable', 'array', 'max:3'],
                'buttons.*.id' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
                'buttons.*.title' => ['nullable', 'string', 'max:20'],
                'button_label' => ['nullable', 'string', 'max:20'],
                'sections' => ['nullable', 'array', 'max:10'],
                'sections.*.title' => ['nullable', 'string', 'max:24'],
                'sections.*.rows' => ['nullable', 'array', 'max:10'],
                'sections.*.rows.*.id' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
                'sections.*.rows.*.title' => ['nullable', 'string', 'max:24'],
                'sections.*.rows.*.description' => ['nullable', 'string', 'max:72'],
                // Carousel. The card floor is Meta's, but it is not enforced
                // here: a node grows one card at a time and auto-save fires in
                // between. The executor skips a carousel that never got there.
                'card_button_type' => ['nullable', 'string', Rule::in(['quick_reply', 'cta_url'])],
                'cards' => ['nullable', 'array', 'max:'.InteractiveNodes::CAROUSEL_MAX_CARDS],
                'cards.*.header_type' => ['nullable', 'string', Rule::in(['image', 'video'])],
                'cards.*.header_url' => ['nullable', 'string', 'max:2000'],
                'cards.*.body' => ['nullable', 'string', 'max:160'],
                'cards.*.buttons' => ['nullable', 'array', 'max:2'],
                'cards.*.buttons.*.id' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
                'cards.*.buttons.*.title' => ['nullable', 'string', 'max:20'],
                'cards.*.button_label' => ['nullable', 'string', 'max:20'],
                'cards.*.button_url' => ['nullable', 'string', 'max:2000'],
                // What to say when the answer is not on the menu, and how many
                // misses to absorb before leaving through the `invalid` branch.
                // Both only matter off WhatsApp, where the menu is text and a
                // silent node is a dead end.
                'invalid_message' => ['nullable', 'string', 'max:1024'],
                'invalid_attempts' => ['nullable', 'integer', 'min:1', 'max:'.InteractiveNodes::MAX_INVALID_ATTEMPTS],
            ],
            'http_request' => [
                'method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
                // nullable so an in-progress node doesn't break auto-save; the
                // executor takes the error branch when the URL is empty at runtime.
                'url' => ['nullable', 'string', 'max:2000'],
                'headers' => ['nullable', 'array'],
                'headers.*.key' => ['nullable', 'string', 'max:255'],
                'headers.*.value' => ['nullable', 'string', 'max:2000'],
                'body' => ['nullable', 'string'],
                'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
                'response_mappings' => ['nullable', 'array'],
                'response_mappings.*.path' => ['nullable', 'string', 'max:255'],
                'response_mappings.*.variable' => ['nullable', 'string', 'max:255'],
            ],
            // Nullable throughout for the reason every other node is: the
            // builder auto-saves a node the moment it lands, and the executor
            // takes the failed branch (payment) or skips (pixel, go_to_flow)
            // for one that never got configured. What is strict is ownership:
            // an integration or a flow must belong to this workspace — and to
            // the right category — or the save is refused.
            'payment' => [
                'integration_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('integrations', 'id')
                        ->where('tenant_id', self::tenantId())
                        ->whereIn('provider', self::providersFor(IntegrationCategory::Payment)),
                ],
                'method' => ['nullable', 'string', Rule::in(PaymentNodes::METHODS)],
                'amount' => ['nullable', 'string', 'max:64'],
                'description' => ['nullable', 'string', 'max:140'],
                'expires_in_minutes' => ['nullable', 'integer', 'min:'.PaymentNodes::MIN_EXPIRES_MINUTES, 'max:'.PaymentNodes::MAX_EXPIRES_MINUTES],
                'message' => ['nullable', 'string', 'max:2000'],
                'send_qr_code' => ['nullable', 'boolean'],
                'send_copy_paste' => ['nullable', 'boolean'],
                'send_link' => ['nullable', 'boolean'],
                'payer_email' => ['nullable', 'string', 'max:255'],
                'payer_document' => ['nullable', 'string', 'max:64'],
            ],
            // The same leniency as payment: a half-built node saves, and at
            // runtime one without an integration, an amount, a description or
            // a CPF/CNPJ takes the failed branch with a note saying which.
            'invoice' => [
                'integration_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('integrations', 'id')
                        ->where('tenant_id', self::tenantId())
                        ->whereIn('provider', self::providersFor(IntegrationCategory::Invoice)),
                ],
                'amount' => ['nullable', 'string', 'max:64'],
                'description' => ['nullable', 'string', 'max:2000'],
                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_document' => ['nullable', 'string', 'max:64'],
                'customer_email' => ['nullable', 'string', 'max:255'],
                'customer_address' => ['nullable', 'array'],
                'customer_address.postal_code' => ['nullable', 'string', 'max:64'],
                'customer_address.street' => ['nullable', 'string', 'max:255'],
                'customer_address.number' => ['nullable', 'string', 'max:64'],
                'customer_address.complement' => ['nullable', 'string', 'max:255'],
                'customer_address.district' => ['nullable', 'string', 'max:255'],
                'customer_address.city' => ['nullable', 'string', 'max:255'],
                'customer_address.state' => ['nullable', 'string', 'max:64'],
                'wait_minutes' => ['nullable', 'integer', 'min:'.InvoiceNodes::MIN_WAIT_MINUTES, 'max:'.InvoiceNodes::MAX_WAIT_MINUTES],
                'message' => ['nullable', 'string', 'max:2000'],
                'send_pdf' => ['nullable', 'boolean'],
                'send_email' => ['nullable', 'boolean'],
            ],
            'pixel' => [
                'integration_ids' => ['nullable', 'array', 'max:10'],
                'integration_ids.*' => [
                    'integer',
                    Rule::exists('integrations', 'id')
                        ->where('tenant_id', self::tenantId())
                        ->whereIn('provider', self::providersFor(IntegrationCategory::Pixel)),
                ],
                'event' => ['nullable', 'string', Rule::in(PixelEvents::EVENTS)],
                'custom_event_name' => ['nullable', 'string', 'regex:'.PixelEvents::CUSTOM_NAME_PATTERN],
                'value' => ['nullable', 'string', 'max:64'],
                'currency' => ['nullable', 'string', 'size:3'],
                'parameters' => ['nullable', 'array', 'max:20'],
                'parameters.*.key' => ['nullable', 'string', 'max:40'],
                'parameters.*.value' => ['nullable', 'string', 'max:500'],
            ],
            'go_to_flow' => [
                'flow_id' => ['nullable', 'integer', Rule::exists('flows', 'id')->where('tenant_id', self::tenantId())],
                'carry_variables' => ['nullable', 'boolean'],
            ],
            // A stage is scoped through its pipeline: stages carry no tenant of
            // their own, and a node naming another workspace's column would
            // otherwise move this workspace's cards into it.
            'lead' => [
                'pipeline_id' => ['nullable', 'integer', Rule::exists('lead_pipelines', 'id')->where('tenant_id', self::tenantId())],
                'stage_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('lead_stages', 'id')->where(function ($query) {
                        $tenantId = self::tenantId();
                        $query->whereIn('pipeline_id', fn ($sub) => $sub
                            ->select('id')
                            ->from('lead_pipelines')
                            ->where('tenant_id', $tenantId));
                    }),
                ],
                'only_forward' => ['nullable', 'boolean'],
                'title' => ['nullable', 'string', 'max:255'],
                'value' => ['nullable', 'string', 'max:64'],
                'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', self::tenantId())],
                'lost_reason' => ['nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    /**
     * The workspace these rules are scoped to.
     *
     * Zero when there is no workspace behind the request — which happens on one
     * real path: the Back Office's "test the flow assistant" button, where the
     * actor is an Admin (a different table, with no tenant) and the model may
     * still produce a node referencing a tag or an agent. Zero matches nothing,
     * so those references fail validation and the assistant is told the tag
     * does not exist, which is both true and actionable. Reading the property
     * off an Admin would instead throw somewhere far from the cause.
     */
    private static function tenantId(): int
    {
        return (int) (auth()->user()?->tenant_id ?? 0);
    }

    /**
     * The providers of a category this workspace's country may connect.
     *
     * Narrower than the category itself, and it has to be: an integration a
     * market cannot create is one a node here must not be able to point at. An
     * empty list matches nothing, which is the honest answer — a country with no
     * invoice issuer has no invoice node to configure.
     *
     * @return list<string>
     */
    private static function providersFor(IntegrationCategory $category): array
    {
        return MarketCapabilities::providerValuesFor(
            auth()->user()?->tenant?->market_code,
            $category,
        );
    }

    /**
     * Validate every node's `data` against its type, throwing the same
     * per-node ValidationException keys the builder and the import screen have
     * always shown.
     */
    public static function validateNodes(array $nodes, string $keyPrefix = 'nodes'): void
    {
        foreach ($nodes as $index => $node) {
            $type = $node['type'];
            $data = $node['data'] ?? null;

            if ($data === null) {
                if ($type === 'start') {
                    continue;
                }

                throw ValidationException::withMessages([
                    "{$keyPrefix}.{$index}.data" => ["The data field is required for node type {$type}."],
                ]);
            }

            $validator = Validator::make($data, self::rulesFor($type));

            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["{$keyPrefix}.{$index}.data.{$field}"] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }
    }

    /**
     * Everything that makes a set of nodes and edges a flow rather than a bag
     * of nodes, reported as plain sentences instead of thrown.
     *
     * Plain sentences because the assistant's repair loop is the main caller:
     * a model handed "flow.nodes.3.data.variable_key is required" fixes the
     * node it names, and a model handed an exception fixes nothing. The
     * importer keeps throwing — {@see assertStructure()} — because a person
     * reading an error about a file they did not write needs the field name.
     *
     * @param  list<array>  $nodes  each with `key` and `type`
     * @param  list<array>  $edges  each with `source_key` and `target_key`
     * @return list<string> empty when the flow is sound
     */
    public static function structureProblems(array $nodes, array $edges): array
    {
        $problems = [];

        $keys = array_map(fn ($node) => (string) ($node['key'] ?? ''), $nodes);

        if (in_array('', $keys, true)) {
            $problems[] = 'Every node needs a non-empty "key".';
        }

        $duplicates = array_unique(array_diff_assoc($keys, array_unique($keys)));
        foreach ($duplicates as $duplicate) {
            $problems[] = "Duplicate node key \"{$duplicate}\" — every key must be unique within the flow.";
        }

        $startCount = count(array_filter($nodes, fn ($node) => ($node['type'] ?? null) === 'start'));
        if ($startCount !== 1) {
            $problems[] = "A flow must contain exactly one node of type \"start\" (found {$startCount}).";
        }

        $keySet = array_flip($keys);
        $byKey = [];
        foreach ($nodes as $node) {
            $byKey[(string) ($node['key'] ?? '')] = $node;
        }

        foreach ($edges as $edge) {
            $source = (string) ($edge['source_key'] ?? '');
            $target = (string) ($edge['target_key'] ?? '');

            if (! isset($keySet[$source])) {
                $problems[] = "Edge source_key \"{$source}\" does not match any node key.";

                continue;
            }

            if (! isset($keySet[$target])) {
                $problems[] = "Edge target_key \"{$target}\" does not match any node key.";

                continue;
            }

            $problems = array_merge($problems, self::branchProblems($byKey[$source], $edge));
        }

        $problems = array_merge($problems, self::duplicateOutputProblems($edges, $keySet));

        // An orphan is not a validation failure — the save endpoint takes it —
        // but it is always a mistake in generated output, and it is invisible
        // on a canvas until someone tests the flow and nothing happens.
        $reachable = self::reachableKeys($nodes, $edges);
        foreach ($nodes as $node) {
            $key = (string) ($node['key'] ?? '');
            if ($key !== '' && ! isset($reachable[$key])) {
                $problems[] = "Node \"{$key}\" cannot be reached from the start node — every node needs an incoming edge.";
            }
        }

        return array_merge($problems, self::incompleteNodeProblems($nodes));
    }

    /**
     * Fields the save endpoint lets a person leave empty while building, but
     * that generated output has no reason to leave out.
     *
     * The builder saves a node the moment it lands and its author fills it in
     * one field at a time, so the save rules are lenient where a half-built
     * node is harmless at runtime. A model hands over a finished flow in one
     * go: an AI node without an agent there is a step it described in `reply`
     * and did not build, and the repair loop should hear about it.
     *
     * @param  list<array>  $nodes
     * @return list<string>
     */
    private static function incompleteNodeProblems(array $nodes): array
    {
        $problems = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) !== 'ai_agent') {
                continue;
            }

            $key = (string) ($node['key'] ?? '');
            $data = (array) ($node['data'] ?? []);

            if (empty($data['ai_hub_agent_id'])) {
                $problems[] = "Node \"{$key}\" (ai_agent) needs an ai_hub_agent_id from the AI agents listed in the context. If none is listed, do not use this node type.";
            }

            if (trim((string) ($data['welcoming_message'] ?? '')) === '') {
                $problems[] = "Node \"{$key}\" (ai_agent) needs a welcoming_message.";
            }
        }

        return $problems;
    }

    /**
     * Whether an edge leaving this node carries a branch value the node can
     * actually emit.
     *
     * The pattern check in the request rules only says the value is well
     * formed; this says it is one this node produces. A `condition` node wired
     * with "yes"/"no" instead of "true"/"false" saves cleanly and then never
     * takes a branch at runtime — the worst kind of generated output, because
     * it looks finished.
     *
     * @return list<string>
     */
    private static function branchProblems(array $sourceNode, array $edge): array
    {
        $type = $sourceNode['type'] ?? '';
        $value = $edge['condition_value'] ?? null;
        $sourceKey = (string) ($sourceNode['key'] ?? '');

        if (isset(self::FIXED_BRANCHES[$type])) {
            $allowed = self::FIXED_BRANCHES[$type];

            if (! in_array($value, $allowed, true)) {
                $shown = $value === null ? 'null' : "\"{$value}\"";
                $list = '"'.implode('", "', $allowed).'"';

                return ["Edge from node \"{$sourceKey}\" ({$type}) has condition_value {$shown}; it must be one of {$list}."];
            }

            return [];
        }

        if ($type === 'interactive') {
            $data = $sourceNode['data'] ?? [];
            $ids = array_column(InteractiveNodes::options($data), 'id');

            // A carousel of link-out cards has no branches at all: tapping one
            // leaves WhatsApp and the flow moves straight on, so its single
            // edge is a plain one.
            if ($ids === []) {
                return [];
            }

            // Everything that waits for a pick also emits `invalid` — the one
            // branch the author did not invent, taken when the answer is not on
            // the menu. It is optional: drawing no edge from it leaves the node
            // waiting, exactly as it did before the branch existed.
            $ids[] = InteractiveNodes::BRANCH_INVALID;

            if (! in_array($value, $ids, true)) {
                $shown = $value === null ? 'null' : "\"{$value}\"";
                $list = '"'.implode('", "', $ids).'"';

                return ["Edge from interactive node \"{$sourceKey}\" has condition_value {$shown}; it must be one of that node's option ids: {$list}."];
            }

            return [];
        }

        // A terminal node's edge saves fine and is simply never followed —
        // which, in generated output, is a step the person was told about that
        // will never happen.
        if (in_array($type, self::TERMINAL_NODE_TYPES, true)) {
            return ["Node \"{$sourceKey}\" ({$type}) ends the flow, so nothing may follow it — remove the edge leaving it."];
        }

        if ($value !== null && $value !== '') {
            return ["Edge from node \"{$sourceKey}\" ({$type}) must not carry a condition_value — only condition, wait_response, http_request, payment, invoice and interactive nodes branch."];
        }

        return [];
    }

    /**
     * Two edges leaving the same output: a step that is drawn and never taken.
     *
     * Nothing in the engine fans out. A conversation sits at one node
     * (`flow_states.current_node_id`) and every "move on" reads a single edge,
     * so the second edge from an output is not a second path — it is a branch
     * the author was shown and the customer will never walk. Refused here
     * rather than dropped ({@see dedupeEdges()} does the dropping for people)
     * because generated output is the one caller that can be asked to try
     * again: the model is told which output, repairs it, and nobody sees it.
     *
     * Branch values each own their bucket, so a condition's true/false and an
     * interactive node's options are peers, not duplicates.
     *
     * @param  list<array>  $edges
     * @param  array<string, int>  $keySet
     * @return list<string>
     */
    private static function duplicateOutputProblems(array $edges, array $keySet): array
    {
        $counts = [];

        foreach ($edges as $edge) {
            $source = (string) ($edge['source_key'] ?? '');

            // Unresolved sources are already reported, and counting them would
            // say the same thing twice about one broken edge.
            if (! isset($keySet[$source])) {
                continue;
            }

            $branch = (string) ($edge['condition_value'] ?? '');
            $counts[$source][$branch] = ($counts[$source][$branch] ?? 0) + 1;
        }

        $problems = [];

        foreach ($counts as $source => $branches) {
            foreach ($branches as $branch => $count) {
                if ($count < 2) {
                    continue;
                }

                $output = ((string) $branch) === ''
                    ? "node \"{$source}\""
                    : "the \"{$branch}\" branch of node \"{$source}\"";

                $problems[] = "{$count} edges leave {$output}; an output leads to exactly one node, and the flow would only ever follow one of them.";
            }
        }

        return $problems;
    }

    /**
     * Node keys reachable from the start node by following edges.
     *
     * @return array<string, true>
     */
    private static function reachableKeys(array $nodes, array $edges): array
    {
        $start = null;
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                $start = (string) ($node['key'] ?? '');
                break;
            }
        }

        if ($start === null) {
            return [];
        }

        $outgoing = [];
        foreach ($edges as $edge) {
            $outgoing[(string) ($edge['source_key'] ?? '')][] = (string) ($edge['target_key'] ?? '');
        }

        $seen = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($outgoing[$current] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $seen;
    }

    /**
     * The import screen's structural checks, thrown rather than listed.
     *
     * Deliberately a subset of {@see structureProblems()}: an exported flow
     * with an unreachable node is a flow somebody built that way, and refusing
     * to import their own file over a shape we merely disapprove of would be
     * this validator overreaching.
     */
    public static function assertStructure(array $nodes, array $edges): void
    {
        $keys = array_map(fn ($node) => $node['key'], $nodes);

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['flow.nodes' => ['Duplicate node keys in the flow export.']]);
        }

        if (collect($nodes)->where('type', 'start')->count() !== 1) {
            throw ValidationException::withMessages(['flow.nodes' => ['A flow must contain exactly one start node.']]);
        }

        $keySet = array_flip($keys);
        foreach ($edges as $edge) {
            if (! isset($keySet[$edge['source_key']]) || ! isset($keySet[$edge['target_key']])) {
                throw ValidationException::withMessages(['flow.edges' => ['An edge references a node that is not in the flow.']]);
            }
        }
    }

    /**
     * One edge per output, for the callers that must not refuse.
     *
     * The rule is the one {@see duplicateOutputProblems()} explains: an output
     * is a handle, a handle leads to one node, and the engine only ever walks
     * one edge. What differs here is the answer to a flow that already breaks
     * it — and it has to differ, because a person is holding it.
     *
     * Auto-save fires three seconds after any change. Refusing would make a
     * flow carrying a duplicate unsavable: every later edit rejected over an
     * edge the author did not draw and, until the canvas started marking them,
     * could not tell apart from the live one. Duplicates were drawable until
     * May 2026, an export of such a flow still carries them, and a direct API
     * call or a stale builder tab can still send them — so they are dropped,
     * and the caller is handed what was dropped to say so.
     *
     * Kept: the first edge of each output, which is the one the executor was
     * already following (it reads `outgoingEdges()` in id order, and edges are
     * recreated in the order they arrive). The dropped ones were dead before
     * this ran.
     *
     * Left alone on purpose: a branching node holding both a labelled and a
     * plain edge. Those are different outputs here, and that pairing is the
     * legacy shape {@see LegacyWaitUpgrade} and the executor's whereNull()
     * fallback still deliberately serve.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  string  $sourceField  'source_node_id' when saving, 'source_key' on import
     * @return array{edges: list<array<string, mixed>>, dropped: list<array<string, mixed>>}
     */
    public static function dedupeEdges(array $edges, string $sourceField): array
    {
        $kept = [];
        $dropped = [];
        $seen = [];

        foreach ($edges as $edge) {
            // NUL as the join, because it is the one byte that cannot appear in
            // a node id, an export key or a branch value — so no pair of them
            // can spell another pair's output.
            $output = ((string) ($edge[$sourceField] ?? ''))."\0".((string) ($edge['condition_value'] ?? ''));

            if (isset($seen[$output])) {
                $dropped[] = $edge;

                continue;
            }

            $seen[$output] = true;
            $kept[] = $edge;
        }

        return ['edges' => $kept, 'dropped' => $dropped];
    }

    /**
     * The whole contract as prose, for the assistant's system prompt.
     *
     * Values come from the constants above rather than being spelled out, so a
     * raised limit or a new message type reaches the model without anyone
     * remembering to rewrite this. What cannot be derived is what a field
     * *means* — that is written by hand, for the same reason JsonFormat.tsx is.
     */
    public static function specification(): string
    {
        $messageTypes = self::quoted(MessageNodes::MESSAGE_TYPES);
        $maxItems = MessageNodes::MAX_ITEMS;
        $maxDelay = MessageNodes::MAX_DELAY_SECONDS;
        $maxWaitSeconds = WaitResponseNodes::MAX_TIMEOUT_SECONDS;
        $maxWaitDays = intdiv(WaitResponseNodes::MAX_TIMEOUT_SECONDS, 86400);
        $maxBuffer = WaitResponseNodes::MAX_BUFFER_SECONDS;
        $waitUnits = self::quoted(array_keys(WaitResponseNodes::TIMEOUT_UNITS));
        $actionTypes = self::quoted(ActionNodes::TYPES);
        $unavailable = self::quoted(ActionNodes::UNAVAILABLE_MODES);
        $interactiveTypes = self::quoted(InteractiveNodes::TYPES);
        $carouselMin = InteractiveNodes::CAROUSEL_MIN_CARDS;
        $carouselMax = InteractiveNodes::CAROUSEL_MAX_CARDS;
        $invalidBranch = InteractiveNodes::BRANCH_INVALID;
        $invalidAttempts = InteractiveNodes::MAX_INVALID_ATTEMPTS;
        $holdingLines = AiHoldingMessage::MAX_LINES;
        $holdingAfter = AiHoldingMessage::MAX_AFTER_SECONDS;
        $resolved = ConversationStatus::Resolved->value;
        $replied = WaitResponseNodes::BRANCH_REPLIED;
        $timeout = WaitResponseNodes::BRANCH_TIMEOUT;
        $format = self::EXPORT_FORMAT;
        $version = self::EXPORT_VERSION;
        $paid = PaymentNodes::BRANCH_PAID;
        $failed = PaymentNodes::BRANCH_FAILED;
        $paymentMethods = self::quoted(PaymentNodes::METHODS);
        $minExpiry = PaymentNodes::MIN_EXPIRES_MINUTES;
        $maxExpiry = PaymentNodes::MAX_EXPIRES_MINUTES;
        $pixelEvents = self::quoted(PixelEvents::EVENTS);
        $paymentVariables = implode(', ', array_map(fn (string $key) => '{{'.$key.'}}', PaymentNodes::VARIABLES));
        $issued = InvoiceNodes::BRANCH_ISSUED;
        $invoiceFailed = InvoiceNodes::BRANCH_FAILED;
        $minWait = InvoiceNodes::MIN_WAIT_MINUTES;
        $maxWait = InvoiceNodes::MAX_WAIT_MINUTES;
        $invoiceVariables = implode(', ', array_map(fn (string $key) => '{{'.$key.'}}', InvoiceNodes::VARIABLES));

        return <<<SPEC
        # Flow file format ("{$format}", version {$version})

        A flow is a directed graph. `nodes` carry the work, `edges` carry the order.
        Every node has a local `key` (any unique string; use "1", "2", "3"…), a `type`,
        a `data` object shaped by that type, and a canvas position.

        ## Envelope

        {
          "name": "Flow name",
          "nodes": [ { "key": "1", "type": "start", "data": null, "position_x": 0, "position_y": 0 } ],
          "edges": [ { "source_key": "1", "target_key": "2", "condition_value": null } ]
        }

        ## Hard rules

        - Exactly ONE node of type "start". Its `data` is null. It has no incoming edge.
        - Every other node must be reachable from "start" by following edges.
        - `condition_value` is null on ordinary edges. Only condition, wait_response,
          http_request, payment, invoice and interactive nodes branch, and their
          values are fixed:
            condition     → "true" / "false"
            wait_response → "{$replied}" / "{$timeout}"
            http_request  → "success" / "error"
            payment       → "{$paid}" / "{$failed}"
            invoice       → "{$issued}" / "{$invoiceFailed}"
            interactive   → the id of one of that node's own options, or "{$invalidBranch}"
        - status and go_to_flow END the flow: no edge may leave them.
        - Lay the canvas out left to right: x grows by ~280 per step, y separates
          branches by ~180. Never stack two nodes on the same coordinates.
        - When you INSERT a step into an existing chain, the steps after it move
          along: give the new node the position the next one had, and add ~280 to
          the x of that node and of everything after it. Leaving them where they
          were puts two nodes on one spot.
        - Text is written for the end customer, in the language the user is speaking
          to you in (Brazilian Portuguese unless they write otherwise).

        ## Node types

        ### start
        `data`: null. The entry point. One per flow.

        ### message — send one or more bubbles, then move on
        {
          "messages": [ { "message_type": "text", "body": "Olá!", "delay": 0 } ]
        }
        - `messages`: up to {$maxItems} bubbles, sent in order. `delay` is the pause in
          seconds BEFORE that bubble (0–{$maxDelay}).
        - `message_type`: one of {$messageTypes}. Anything but "text" needs
          `attachment_url` (a public URL) — never invent one; use "text" unless the
          user supplied a URL.
        - Never waits: the next node runs right after the last bubble. To stop until
          the customer writes, follow it with a wait_response node.
        - One output.

        ### response — ask a question and store a valid answer
        {
          "body": "Qual é o seu nome?",
          "message_type": "text",
          "variable_key": "nome",
          "validation": "any",
          "error_message": "Não entendi, pode repetir?"
        }
        - `body`, `message_type` and `variable_key` are REQUIRED.
        - `variable_key`: letters, digits, underscore and dash only. Referenced
          later as {{nome}} — a bare key, never dotted.
        - `validation`: "any" | "number" | "email" | "phone".
        - Waits for a valid answer with no deadline. When the flow must give up
          after a while, send the question with a message node and follow it with
          a wait_response node that has `timeout_seconds` instead.
        - One output.

        ### wait_response — pause until the customer writes
        {
          "message": "Me conta o número do seu pedido, por favor.",
          "variable_key": "pedido",
          "timeout_seconds": 3600,
          "timeout_unit": "hours",
          "buffer_seconds": 0,
          "validation": "any",
          "error_message": ""
        }
        - Every field is optional. With none set it waits for the next message and
          moves on through "{$replied}".
        - `message`: text sent before waiting (accepts variables). Empty sends nothing.
        - `variable_key`: where the reply is stored, referenced later as {{pedido}}.
          Same format as the response node's key. Empty stores nothing.
        - `timeout_seconds`: 0 waits indefinitely; up to {$maxWaitSeconds}
          ({$maxWaitDays} days). `timeout_unit` ({$waitUnits}) is only how the
          builder shows it — use the largest unit that divides the value exactly.
        - `buffer_seconds`: 0–{$maxBuffer}. Above 0 the node waits until the customer
          has been quiet that long, then stores everything they sent as one reply.
        - `validation`: "any" | "number" | "email" | "phone". A reply that does not
          fit gets `error_message` (when set) and the node keeps waiting.
        - TWO outputs: "{$replied}" and "{$timeout}". Only wire "{$timeout}" when
          `timeout_seconds` is greater than 0.

        ### condition — branch on a value
        { "field": "variable.nome", "operator": "equals", "value": "sim" }
        - `field`: "variable.{key}" for anything a response, wait_response or
          http_request node stored, or "contact.name" / "contact.phone" / "contact.email" /
          "conversation.status" / "service_hours.is_open".
        - `operator`: equals, not_equals, contains, not_contains, greater_than,
          less_than, is_empty, is_not_empty.
        - `value`: omit (or null) for is_empty / is_not_empty.
        - TWO outputs: "true" and "false". Wire BOTH.

        ### interactive — WhatsApp buttons, a list menu or a carousel
        { "interactive_type": "button", "body": "Como podemos ajudar?",
          "buttons": [ { "id": "btn_suporte", "title": "Suporte" } ] }
        - `interactive_type`: {$interactiveTypes}.
        - "button": up to 3 `buttons`, titles ≤ 20 chars.
        - "list": `button_label` (≤ 20) plus `sections[]`, each with `rows[]`
          (row title ≤ 24, description ≤ 72; 10 sections and 10 rows max).
        - "carousel": `card_button_type` ("quick_reply" or "cta_url") and
          {$carouselMin}–{$carouselMax} `cards`, each with `header_type`
          ("image"/"video"), a public `header_url`, and a body ≤ 160 chars. Only
          propose a carousel when the user gave you media URLs.
        - Option ids: lowercase letters, digits, underscore or dash. Make them
          readable ("btn_suporte", not "1"). Never use "{$invalidBranch}" — that name
          belongs to the branch below.
        - ONE OUTPUT PER OPTION. Each edge's `condition_value` is that option's id.
        - Runs on EVERY channel. Only WhatsApp Official draws real buttons;
          elsewhere the same options go out as a numbered menu and a customer who
          replies "2" takes branch 2, so you never need a message-plus-response
          pair to fake a menu.
        - PLUS one optional output "{$invalidBranch}", taken when the answer is not on
          the menu. Wire it whenever there is somewhere sensible to go (a human
          handoff, a repeat of the menu); leave it unwired to keep waiting.
          `invalid_message` is what to say on a miss and `invalid_attempts`
          (1–{$invalidAttempts}) how many misses to absorb first. Both are worth setting
          whenever the flow may run off WhatsApp: there the menu is text, and a
          node that answers nothing is a dead end.

        ### ai_agent — hand the conversation to a configured AI agent
        { "ai_hub_agent_id": 12, "welcoming_message": "Oi! Sou o assistente virtual." }
        - `ai_hub_agent_id` MUST be the id of one of the workspace's AI agents listed
          in the context below. If none is listed, DO NOT use this node type.
        - `welcoming_message` is REQUIRED.
        - Optional: `store_summary_to_variable`, and `service_hours_behavior`
          ("always_ai" | "handoff_in_hours" | "human_only_in_hours").
        - Optional `holding_message` — what to say when the answer is taking a
          while, so the customer is not left watching a conversation that stopped:
          { "messages": ["Só um instante, estou verificando isso…",
                         "Um momento, já te respondo."],
            "media_messages": ["Deixa eu dar uma olhada no que você enviou…"],
            "after_seconds": 8 }
          Write these in the language the flow speaks to its customers; there is no
          default, and a node without `messages` simply stays silent. Up to
          {$holdingLines} lines each, picked in rotation so a customer who waits
          twice does not read the same sentence twice. `media_messages` is used when
          the customer sent an image, a file or a voice note — omit it and the main
          list covers those too. `after_seconds` (0–{$holdingAfter}, default 8) is
          how long the customer waits before any of it is sent, counted from their
          own message: an answer that arrives quickly is never preceded by an
          apology for a delay that did not happen. Only add this when the user asks
          for it or the flow's agent is likely to be slow (long tool calls, document
          lookups) — an extra bubble on every turn is a cost, not a courtesy.
        - One output (taken when the agent hands off).

        ### tagging — label the conversation or the contact
        { "action": "add", "target": "conversation", "tags": [3, 7] }
        - `tags` are ids from the workspace's tag list in the context below. If the
          tag the user asked for is not there, say so instead of inventing an id.
        - `target`: "conversation" (default, ends with the thread) or "contact"
          (stays with the person across future conversations).
        - One output.

        ### action — decide who ends up holding the conversation
        { "type": "transfer_human", "parameters": {} }
        - `type`: {$actionTypes}.
        - "assign_agent": `parameters.agent_id` from the agent list in the context,
          plus `parameters.when_unavailable` ({$unavailable}). Terminal.
        - "transfer_human": puts the thread in the human queue. Terminal — no
          outgoing edge.
        - "internal_note": `parameters.note` (supports {{variable}}). A note for the
          team, never sent to the customer. This one CONTINUES — it has one output.

        ### http_request — call an external API
        GET, reading a value back out:
        { "method": "GET", "url": "https://api.exemplo.com/pedidos/{{pedido}}",
          "headers": [ { "key": "Authorization", "value": "Bearer SEU_TOKEN" } ],
          "timeout": 15,
          "response_mappings": [
            { "path": "data.status", "variable": "status_pedido" },
            { "path": "http_status", "variable": "codigo_http" }
          ] }
        POST, sending what the customer answered:
        { "method": "POST", "url": "https://api.exemplo.com/leads",
          "headers": [ { "key": "Content-Type", "value": "application/json" } ],
          "body": "{\\"nome\\": \\"{{nome}}\\", \\"telefone\\": \\"{{contact.phone}}\\"}",
          "response_mappings": [ { "path": "id", "variable": "lead_id" } ] }
        - When the person gives you an endpoint, USE THIS NODE — that is what it is
          for. Pick the verb from what they are doing: reading something about the
          customer is GET, recording something is POST. Both work.
        - `url`, every header value and `body` support {{variable}}, so anything a
          response node stored can be sent onward. `body` is a STRING containing
          JSON, not a JSON object — escape the quotes inside it.
        - Send `Content-Type: application/json` yourself on POST/PUT/PATCH; nothing
          adds it for you.
        - `response_mappings[].path` is a dot-path into the JSON response
          ("data.user.name"); the special paths "http_status" and "raw_body" also
          work. Whatever you map becomes a {{variable}} the later nodes can read —
          usually into a message ("Seu pedido está {{status_pedido}}") or a condition.
        - If the endpoint needs a token or a key the person did not give you, put a
          clear placeholder in the header value and say in `reply` exactly which
          node they must open to paste the real one. Never guess a credential.
        - TWO outputs: "success" and "error". Wire BOTH — an unwired error branch is
          a flow that goes silent when the API is down. The error branch should say
          something human and usually hand over to a person.

        ### payment — charge the customer and wait for the money
        { "integration_id": 4, "method": "pix", "amount": "49,90",
          "description": "Pedido {{pedido}}", "expires_in_minutes": 60,
          "message": "Segue o Pix de {{payment_amount}} para finalizar:",
          "send_qr_code": true, "send_copy_paste": true, "send_link": false }
        - `integration_id` MUST be the id of one of the workspace's payment
          integrations listed in the context. If none is listed, DO NOT use this
          node — say in `reply` that a payment gateway has to be connected first
          under Settings → Integrations.
        - `method`: {$paymentMethods}. "checkout" is a link that also takes cards
          and boleto, and only exists on integrations whose `payment_methods`
          include it.
        - `amount`: reais as a person writes them ("49,90") or a variable
          ("{{valor}}"). Never leave it empty.
        - `expires_in_minutes`: {$minExpiry}–{$maxExpiry}.
        - Once the charge exists these variables are set, for this node's own
          `message` and for every later node: {$paymentVariables}.
        - The customer can write while it waits; that does not move the flow. The
          gateway does.
        - TWO outputs: "{$paid}" (the gateway confirmed the money) and "{$failed}"
          (it expired unpaid or could not be created). Wire BOTH — the failed
          branch usually offers a new attempt or hands over to a person.

        ### invoice — issue a nota fiscal (NFS-e) and send it to the customer
        { "integration_id": 9, "amount": "{{payment_value}}",
          "description": "Consultoria online — pedido {{pedido}}",
          "customer_document": "{{cpf}}", "customer_email": "{{email}}",
          "wait_minutes": 30, "send_pdf": true,
          "message": "Sua nota fiscal nº {{invoice_number}} foi emitida. Segue o PDF:" }
        - `integration_id` MUST be the id of one of the workspace's invoice
          integrations listed in the context. If none is listed, DO NOT use this
          node — say in `reply` that a nota fiscal platform has to be connected
          first under Settings → Integrations.
        - `customer_document` (CPF or CNPJ) is REQUIRED when the node runs: collect
          it first with a response node and reference that variable here.
          `customer_name` defaults to the contact's name and `customer_email` to
          the contact's e-mail.
        - `amount` and `description` are required. After a payment node, use
          "{{payment_value}}". The fiscal service codes live on the integration,
          never on the node.
        - Optional `customer_address` (postal_code, street, number, complement,
          district, city, state) — only when the person says their prefeitura
          needs it.
        - `wait_minutes`: {$minWait}–{$maxWait}. The prefeitura or SEFAZ authorizes
          the invoice some time after the request; the flow waits this long.
        - Once authorized, `message` goes out followed by the PDF (when `send_pdf`).
          These variables are set for later nodes: {$invoiceVariables}.
        - The customer writing while it waits does not move the flow.
        - TWO outputs: "{$issued}" (authorized, document sent) and "{$invoiceFailed}"
          (rejected, could not be requested, or still unanswered at the deadline).
          Wire BOTH. Natural place: on a payment node's "{$paid}" branch.

        ### pixel — report a conversion to an ad or analytics account
        { "integration_ids": [7], "event": "purchase", "value": "{{payment_value}}", "currency": "BRL" }
        - `integration_ids`: ids of pixel integrations from the context. If none
          is listed, DO NOT use this node.
        - `event`: {$pixelEvents}. With "custom", also set `custom_event_name`
          (letters, digits and underscore, starting with a letter).
        - Invisible to the customer and never waits. One output.
        - Natural places: "lead" after the customer gave their contact details,
          "purchase" on a payment node's "{$paid}" branch.

        ### lead — put the contact on the sales board
        { "stage_id": 14, "only_forward": true, "value": "{{payment_value}}" }
        - Makes sure the contact has an open lead (opens one when there is none)
          and, when `stage_id` is set, moves the lead to that stage. `stage_id`
          MUST come from the lead stages listed in the context; leave it null to
          only make sure the lead exists. If no lead stages are listed, DO NOT use
          this node.
        - `only_forward` (default true): never moves a lead back to an earlier
          stage of the same pipeline, so a returning customer already in a later
          stage stays there.
        - Optional: `title` and `value` (both accept {{variable}}), `owner_id` (an
          agent from the context), `lost_reason` (only used on a stage whose kind
          is "lost").
        - Afterwards {{lead_id}}, {{lead_stage}} and {{lead_status}} are set.
        - Invisible to the customer. One output.
        - Natural places: a qualification stage right after the customer answered
          the deciding question; the "won" stage on a payment node's "{$paid}"
          branch.

        ### go_to_flow — continue in another flow
        { "flow_id": 12, "carry_variables": true }
        - `flow_id` MUST be the id of one of the workspace's OTHER flows listed in
          the context — never the flow being edited.
        - `carry_variables`: whether the answers collected so far go along.
        - TERMINAL: no outgoing edge. The other flow's start node takes over.

        ### status — close the conversation
        { "value": "{$resolved}" }
        - "{$resolved}" is the only allowed value. TERMINAL: no outgoing edge.
        SPEC;
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return '"'.implode('" | "', $values).'"';
    }
}
