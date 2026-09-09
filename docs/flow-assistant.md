# Flow assistant — the AI that writes flows

The flow builder has a chat panel that turns "atendimento para uma pizzaria" into a
flow, and edits the one already on the canvas. It runs on the **platform's own
OpenAI key**, through **our own AI Hub** — never a workspace's key, never a
workspace's prepaid balance.

---

## The one promise

**A blueprint that reaches the browser is a blueprint the save endpoint accepts.**

Everything unusual in the implementation follows from that sentence. A generated
flow that lands on the canvas and then refuses to save is worse than no
assistant: the customer cannot tell whether they asked for something impossible
or the product is broken, and the canvas is now dirty.

How it is kept:

1. `App\Services\Flow\FlowBlueprint` owns the flow file contract — the per-node
   validation rules **and** the specification handed to the model. Saving,
   importing and the assistant validate against one definition. A second copy of
   the rules written for the prompt would be right on the day it was written and
   quietly wrong after the next node type.
2. Every answer carrying a flow is validated with those rules plus structural
   checks (one start node, edges resolve, branch values a node can actually
   emit, nothing unreachable).
3. A blueprint that fails is handed **back to the model** with its errors, as
   sentences rather than exception keys. Up to `MAX_REPAIRS` (2) times.
4. Still failing → the caller gets prose and `flow: null`. The panel shows what
   went wrong and touches nothing.

⚠️ Because the rules live in one place, **adding a node type or changing a rule
reaches the prompt automatically** — `specification()` interpolates the
constants. Do not retype values into that prose.

---

## Setup (Back Office)

**Integrations → AI Hub → Flow assistant (OpenAI).**

1. The **AI Hub tenant token** above it must already be set — the assistant
   rides the same platform token as every other hub call.
2. Paste an **OpenAI API key** and pick a **model** (default `gpt-4o`).
3. Tick **Enable the flow assistant**, Save.
4. **Test connection** runs a real turn and reports how many nodes came back.
   Deliberately a real turn, not a ping: a key with no quota or a model name the
   hub does not carry is configured correctly and still fails.

Saving provisions two things at the hub, idempotently: a provider credential
holding the key, and the agent `platform_flow_assistant` carrying the system
prompt. Pressing Save twice does not create a second agent.

⚠️ **`providerCredentialId` at the hub is an id, not the key.** The key is sent
once, on `POST /provider-credentials`; the hub returns an id and that is what is
stored. (Same trap as `AiTranscription::credentialId()`.)

⚠️ **A 409 is adopted, not reported.** The hub uniques a credential on
`(tenant, provider, name)` and an agent on `externalId`, so once either exists
without us holding its id, every re-create answers 409 and provisioning can
never finish again from any screen. Provisioning therefore lists, finds the one
we already own, and takes it back — the same dead end
`AiTokenRentalService::rent()` had to grow an adoption path for.

### Then give it to a plan

Configuring the platform grants it to nobody. The workspace-facing switch is the
plan feature **`flow_assistant`** (`App\Enums\Billing\Feature`), edited in
**Back Office → Plans**. It is separate from `flow` on purpose: a plan can sell
the builder without selling the assistant, which costs the platform on every
turn. Giving `flow_assistant` to a plan without `flow` opens a panel onto a page
that plan cannot reach.

---

## Where the settings live

Table `settings`, read through `App\Services\FlowAssistant\FlowAssistantConfig`:

| Key | What it is |
|---|---|
| `flow_assistant.enabled` | The operator's switch |
| `flow_assistant.model` | e.g. `gpt-4o` |
| `flow_assistant.openai_api_key` | The platform's key (encrypted) |
| `flow_assistant.hub_credential_id` | Hub id, returned by the hub |
| `flow_assistant.hub_agent_id` | Hub id |
| `flow_assistant.agent_external_id` | `platform_flow_assistant` |
| `flow_assistant.prompt_hash` | Hash of the last prompt pushed |

**Not in `ai_hub_tenants` / `ai_hub_agents`.** Every row in those hangs off a
customer `tenant_id` and is listed to that customer — parking the assistant
there would leak a platform agent into somebody's agent list, where they could
edit its prompt or delete it and take the feature down for everyone.

### `prompt_hash` — why a deploy repairs itself

The system prompt is built from `FlowBlueprint::specification()`, so it changes
whenever the flow format does. On the first turn after such a deploy the stored
hash no longer matches and the prompt is re-pushed to the hub, once. Without
this the assistant would keep describing the format it was provisioned against —
writing fields that no longer exist, failing validation every time, with nothing
on any screen saying why.

---

## How a turn works

`POST /api/flows/{id}/assistant/stream` (SSE) — or `/assistant` for the same
work as one JSON response.

⚠️ **`conversation.channel` is sent as `whatsapp`.** There is no real
conversation here, so the field is pure ceremony for the hub's DTO — which means
the only thing that matters is that the hub accepts the value. It was
`live_chat_widget` at first, which this application *can* send but which no
workspace here has ever exercised (nobody runs an AI agent on the widget); an
unproven enum value chosen for tidiness. The hub rejects a whole run over one
unrecognised field, so guessing costs the entire feature.

⚠️ **Hub failures are parsed with `AiAgentHubTenantService::hubMessage()`**, the
same parser the workspace-facing client uses. Passing `$response->body()`
straight to `UpstreamError` — which is what the first version did — guarantees
the worst possible outcome: a hub 400 reads
`{"message":["..."],"statusCode":400}`, matches nothing in the dictionary, and
*every* failure surfaces as the generic "O serviço de IA está indisponível" with
the real reason nowhere a person would look.

**Turns are stateless.** Each one gets a fresh hub `conversation.externalId` and
carries its own context: the workspace's real tags / agents / AI agents, the
flow exactly as it stands, the recent transcript (words only), and the request.

The hub *could* remember the dialogue, but the largest thing in every turn is
the current flow, it changes every turn, and hub-side history would accumulate
one full copy per turn until the context is mostly stale versions of the same
graph.

The transcript is held by the browser and sent back. It is the person's own
conversation with themselves, so there is nothing to leak — but it is client
input, hence the caps (`MAX_HISTORY_TURNS`, `MAX_MESSAGE_CHARS`).

### Real ids, never invented ones

The model is given the workspace's actual tag ids, agent ids and AI agent ids,
and told never to invent one. The validator is what enforces it: an invented id
fails, the repair loop is told so, and the assistant removes the node and
explains rather than saving something that would attach a stranger's label.

### The stream

Events are real server milestones, not a progress bar:

| Event | Meaning |
|---|---|
| `status` `{stage: thinking}` | The hub call is out |
| `status` `{stage: validating}` | An answer came back with a flow |
| `status` `{stage: repairing, attempt: n}` | It failed; going back to the model |
| `result` `{reply, flow, warnings}` | Done |
| `error` `{message}` | Already translated, safe to show |

⚠️ **`X-Accel-Buffering: no` and `ob_flush()` are load-bearing.** Without them
nginx/Caddy and PHP-FPM hold every event until the handler returns and they all
arrive at once — the exact opposite of the point.

⚠️ The browser uses `fetch` + `ReadableStream`, **not `EventSource`**.
EventSource cannot set headers, and an SSE endpoint taking a bearer token in the
query string would put it in every proxy access log. The client falls back to
the plain JSON endpoint when the stream cannot be established — SSE is the first
thing a buffering proxy breaks, and a feature that is merely slower is much
better than one that appears broken.

---

## Applying to the canvas

The panel **proposes**; the person applies. Nothing is written until they press
the button, because the canvas auto-saves three seconds after any change and an
auto-apply would commit a rewrite of a flow customers are being routed through
right now. One undo step is kept.

Applying is a whole-canvas replacement — the assistant always returns the
complete flow, never a patch. A blueprint key matching a node already on the
canvas keeps that node's id (so the save endpoint UPDATEs it); everything else
gets a fresh non-numeric id (which is how that endpoint recognises a new node).
The start node is pinned to the existing one either way.

---

## Costs

The platform pays for every turn, including repairs. Nothing is charged to the
workspace and nothing counts against `max_ai_runs` — the assistant deliberately
does **not** go through `AiAgentHubTenantService::runAgent()`, which exists to
answer a customer and carries quota, billing and an `ai_hub_runs` row keyed to a
workspace agent.

The consequence: **no usage row is written.** `ai_hub_runs` cannot hold these
(its agent FK points at a workspace-owned agent), so the audit trail is the log:

```
grep 'flow assistant turn' storage/logs/laravel.log
```

⚠️ Production keeps no application logs across deploys (`storage/logs` lives
inside the container). If per-turn cost ever needs reporting rather than
spot-checking, it needs its own table.

## Diagnosing

| Symptom | Where to look |
|---|---|
| Panel button missing | Plan lacks `flow_assistant`, or `GET /flows/assistant/status` returns `available: false` (not provisioned) |
| "Não consegui montar um fluxo válido" | `grep 'gave up on a blueprint'` — the problems are logged |
| Repairs on every request | `grep 'repairing an invalid blueprint'`; usually the prompt and the rules have drifted — check `prompt_hash` |
| Everything 503 | AI Hub tenant token missing (Integrations → AI Hub) |
| Generic "O serviço de IA está indisponível" | The hub refused something. **Press Test connection in the Back Office** — it prints the hub's own sentence plus HTTP status and `ref`. In the log: `grep 'Upstream failure translated' \| grep ai_hub` (field `upstream_message`) |
| Stream arrives all at once | Proxy buffering; check `X-Accel-Buffering` survives the edge |

Tests: `tests/Feature/Flow/FlowAssistantTest.php`.
