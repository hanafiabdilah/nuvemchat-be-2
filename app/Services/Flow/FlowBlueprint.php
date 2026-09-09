<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
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
        'start', 'message', 'response', 'status', 'tagging',
        'condition', 'action', 'ai_agent', 'http_request', 'interactive',
    ];

    /**
     * Edge branch values: the fixed pair per branching node — condition
     * (true/false), http_request (success/error), response (replied/timeout) —
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
        'response' => [ResponseNodes::BRANCH_REPLIED, ResponseNodes::BRANCH_TIMEOUT],
    ];

    /**
     * Node types that end the flow and therefore have no outgoing edge.
     * `status` always does; `action` does for two of its three types, which is
     * a per-node question and lives in {@see ActionNodes::isTerminal()}.
     */
    public const TERMINAL_NODE_TYPES = ['status'];

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
                'delay' => ['nullable', 'integer', 'min:0', 'max:' . MessageNodes::MAX_DELAY_SECONDS],
                'wait_for_reply' => ['nullable', 'boolean'],
                'messages' => ['nullable', 'array', 'max:' . MessageNodes::MAX_ITEMS],
                'messages.*.body' => ['nullable', 'string'],
                'messages.*.message_type' => ['nullable', 'string', Rule::in(MessageNodes::MESSAGE_TYPES)],
                'messages.*.attachment_url' => ['nullable', 'string'],
                'messages.*.delay' => ['nullable', 'integer', 'min:0', 'max:' . MessageNodes::MAX_DELAY_SECONDS],
            ],
            'response' => [
                'body' => ['required', 'string'],
                'message_type' => ['required', 'string', Rule::in(MessageNodes::MESSAGE_TYPES)],
                'attachment_url' => ['nullable', 'string'],
                'variable_key' => ['required', 'string'],
                'validation' => ['nullable', 'string', Rule::in(['any', 'number', 'email', 'phone'])],
                'error_message' => ['nullable', 'string'],
                // 0 (or absent) = wait forever, which is what this node did
                // before it grew a second output.
                'timeout_seconds' => ['nullable', 'integer', 'min:0', 'max:' . ResponseNodes::MAX_TIMEOUT_SECONDS],
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
            'ai_agent' => [
                'ai_hub_agent_id' => [
                    'required',
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
                'welcoming_message' => ['required', 'string', 'max:4000'],
                'store_summary_to_variable' => ['nullable', 'string', 'alpha_dash'],
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
                'cards' => ['nullable', 'array', 'max:' . InteractiveNodes::CAROUSEL_MAX_CARDS],
                'cards.*.header_type' => ['nullable', 'string', Rule::in(['image', 'video'])],
                'cards.*.header_url' => ['nullable', 'string', 'max:2000'],
                'cards.*.body' => ['nullable', 'string', 'max:160'],
                'cards.*.buttons' => ['nullable', 'array', 'max:2'],
                'cards.*.buttons.*.id' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
                'cards.*.buttons.*.title' => ['nullable', 'string', 'max:20'],
                'cards.*.button_label' => ['nullable', 'string', 'max:20'],
                'cards.*.button_url' => ['nullable', 'string', 'max:2000'],
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

            // A response node's edges saved before it grew a second output
            // carry no branch value; they mean "replied", which is the output
            // they always were. Reading that as an error would condemn every
            // flow written before the timeout branch existed.
            if ($value === null && $type === 'response') {
                return [];
            }

            if (! in_array($value, $allowed, true)) {
                $shown = $value === null ? 'null' : "\"{$value}\"";
                $list = '"' . implode('", "', $allowed) . '"';

                return ["Edge from node \"{$sourceKey}\" ({$type}) has condition_value {$shown}; it must be one of {$list}."];
            }

            return [];
        }

        if ($type === 'interactive') {
            $options = InteractiveNodes::options($sourceNode['data'] ?? []);
            $ids = array_column($options, 'id');

            // A carousel of link-out cards has no branches at all: tapping one
            // leaves WhatsApp and the flow moves straight on, so its single
            // edge is a plain one.
            if ($ids === []) {
                return [];
            }

            if (! in_array($value, $ids, true)) {
                $shown = $value === null ? 'null' : "\"{$value}\"";
                $list = $ids === [] ? '(none)' : '"' . implode('", "', $ids) . '"';

                return ["Edge from interactive node \"{$sourceKey}\" has condition_value {$shown}; it must be one of that node's option ids: {$list}."];
            }

            return [];
        }

        if ($value !== null && $value !== '') {
            return ["Edge from node \"{$sourceKey}\" ({$type}) must not carry a condition_value — only condition, response, http_request and interactive nodes branch."];
        }

        return [];
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
        $maxTimeout = ResponseNodes::MAX_TIMEOUT_SECONDS;
        $actionTypes = self::quoted(ActionNodes::TYPES);
        $unavailable = self::quoted(ActionNodes::UNAVAILABLE_MODES);
        $interactiveTypes = self::quoted(InteractiveNodes::TYPES);
        $carouselMin = InteractiveNodes::CAROUSEL_MIN_CARDS;
        $carouselMax = InteractiveNodes::CAROUSEL_MAX_CARDS;
        $resolved = ConversationStatus::Resolved->value;
        $replied = ResponseNodes::BRANCH_REPLIED;
        $timeout = ResponseNodes::BRANCH_TIMEOUT;
        $format = self::EXPORT_FORMAT;
        $version = self::EXPORT_VERSION;

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
        - `condition_value` is null on ordinary edges. Only condition, response,
          http_request and interactive nodes branch, and their values are fixed:
            condition     → "true" / "false"
            response      → "{$replied}" / "{$timeout}"
            http_request  → "success" / "error"
            interactive   → the id of one of that node's own options
        - Lay the canvas out left to right: x grows by ~280 per step, y separates
          branches by ~180. Never stack two nodes on the same coordinates.
        - Text is written for the end customer, in the language the user is speaking
          to you in (Brazilian Portuguese unless they write otherwise).

        ## Node types

        ### start
        `data`: null. The entry point. One per flow.

        ### message — send one or more bubbles, then move on
        {
          "wait_for_reply": false,
          "messages": [ { "message_type": "text", "body": "Olá!", "delay": 0 } ]
        }
        - `messages`: up to {$maxItems} bubbles, sent in order. `delay` is the pause in
          seconds BEFORE that bubble (0–{$maxDelay}).
        - `message_type`: one of {$messageTypes}. Anything but "text" needs
          `attachment_url` (a public URL) — never invent one; use "text" unless the
          user supplied a URL.
        - `wait_for_reply`: false in almost every case. Use the response node when
          you need an answer; this flag is a legacy pause with no branch and no
          variable, so a flow that needs an answer should not use it.
        - One output.

        ### response — ask a question and store the answer
        {
          "body": "Qual é o seu nome?",
          "message_type": "text",
          "variable_key": "nome",
          "validation": "any",
          "error_message": "Não entendi, pode repetir?",
          "timeout_seconds": 0
        }
        - `body`, `message_type` and `variable_key` are REQUIRED.
        - `variable_key`: letters, digits, underscore and dash only. Referenced
          later as {{nome}} — a bare key, never dotted.
        - `validation`: "any" | "number" | "email" | "phone".
        - `timeout_seconds`: 0 (or absent) waits forever. Up to {$maxTimeout}.
        - TWO outputs: "{$replied}" and "{$timeout}". Only wire "{$timeout}" when
          `timeout_seconds` is greater than 0.

        ### condition — branch on a value
        { "field": "variable.nome", "operator": "equals", "value": "sim" }
        - `field`: "variable.{key}" for anything a response or http_request node
          stored, or "contact.name" / "contact.phone" / "contact.email" /
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
          readable ("btn_suporte", not "1").
        - ONE OUTPUT PER OPTION. Each edge's `condition_value` is that option's id.
        - WhatsApp Official ONLY. If you do not know the channel, prefer a message
          node with numbered choices plus a response node.

        ### ai_agent — hand the conversation to a configured AI agent
        { "ai_hub_agent_id": 12, "welcoming_message": "Oi! Sou o assistente virtual." }
        - `ai_hub_agent_id` MUST be the id of one of the workspace's AI agents listed
          in the context below. If none is listed, DO NOT use this node type.
        - `welcoming_message` is REQUIRED.
        - Optional: `store_summary_to_variable`, and `service_hours_behavior`
          ("always_ai" | "handoff_in_hours" | "human_only_in_hours").
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
        { "method": "GET", "url": "https://api.exemplo.com/pedidos/{{pedido}}",
          "headers": [ { "key": "Authorization", "value": "Bearer …" } ],
          "timeout": 15,
          "response_mappings": [ { "path": "data.status", "variable": "status_pedido" } ] }
        - `url` supports {{variable}}. `response_mappings[].path` is a dot-path into
          the JSON response; the special paths "http_status" and "raw_body" also work.
        - TWO outputs: "success" and "error". Wire BOTH — an unwired error branch is
          a flow that goes silent when the API is down.

        ### status — close the conversation
        { "value": "{$resolved}" }
        - "{$resolved}" is the only allowed value. TERMINAL: no outgoing edge.
        SPEC;
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return '"' . implode('" | "', $values) . '"';
    }
}
