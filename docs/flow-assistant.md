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

## The proposal lands on the canvas, not in a card

A flow is a picture, and the only way to judge one is to look at it. So an
answer carrying a blueprint is drawn **on the canvas immediately**, and the
Accept / Discard buttons float over it — next to the thing being judged. There
is no "Apply" button in the chat panel; reading "6 nós, 2 ramificações" and
pressing Apply is agreeing to something unseen.

It builds itself a piece at a time, and the order is the point:

1. what already exists stays put, moving to its new position (CSS transition on
   the node wrapper — React Flow positions with `transform`, so without it a
   rearrangement is a jump);
2. new nodes land one at a time (`NODE_STAGGER_MS`);
3. then the edges that join them (`EDGE_STAGGER_MS`);
4. then the viewport frames the result.

Nodes the proposal **drops** stay on screen, dimmed and red-outlined, until the
decision is made. "It is about to delete this" is the single most important
thing a preview can say, and a node that simply vanished would say it too late.

⚠️ **Nothing is saved while a proposal is on screen.** The auto-save timer's
callback checks `previewRef` at fire time, not when its effect was set up — a
machine-written rewrite of a live flow committing itself three seconds after it
appeared is the exact thing this exists to prevent. Accepting sets
`flushSaveRef` so the next pass saves immediately instead of waiting.

⚠️ **Accepting must not call `handleSave()` directly.** It closes over the
`nodes`/`edges` of the render that created it, and the accept/undo callbacks are
memoised on stable setters — so they capture the *first* render, whose `nodes`
is the empty array the canvas started with. That saved an empty flow. The flag
exists so the save runs from the auto-save effect, which re-runs on every
`[nodes, edges]` change and therefore always holds the current one.

Accepting is a whole-canvas replacement — the assistant always returns the
complete flow, never a patch. A blueprint key matching a node already on the
canvas keeps that node's id (so the save endpoint UPDATEs it); everything else
gets a fresh non-numeric id (which is how that endpoint recognises a new node).
The start node is pinned to the existing one either way. One undo step is kept
after accepting.

⚠️ Preview styling goes through `node.className` and CSS in `app.css`, never
through `node.data` — `data` is the payload posted to the save endpoint, so
anything put there for appearance is written to the database. And the "new node"
keyframe scales the node's **child**: React Flow puts an inline
`transform: translate(...)` on the node itself, so a keyframe touching
`transform` there wins and drops the node at the origin.

## Making room for what was added

`FlowAssistant\FlowLayout`, run from `normalize()` after every answer.

The bug it exists for: insert a step into an existing chain — A → B → C becomes
A → B → D → C — and the model wires it correctly and then puts D exactly where
C is. It has no reason not to; it was told to keep the nodes it is not changing,
so C comes back with the coordinates we sent it, and D gets something plausible.
Two nodes on one spot reads as a node that failed to appear.

The rule, in one sentence: **nodes may not overlap, and when two do, the one
further from the start moves right — together with everything downstream of it**,
because a node that moved without its children would just land on them instead.

- Depth is the **longest** route from the start, not the shortest: in a diamond
  (A → C and A → B → C) the shortest route puts C in B's column.
- Positions that are already there are **kept**. A flow's layout is usually the
  work of somebody who dragged the nodes where they wanted them, and a pass that
  recomputed every coordinate would tidy that away every time the assistant was
  asked for anything. This only ever *adds* space.
- A node with no position at all is placed beside its parent, not at an
  arbitrary index.

⚠️ `MIN_H_SEPARATION` / `MIN_V_SEPARATION` are **separation thresholds, not node
dimensions.** The first version used the node box (300px wide plus a 40px gap),
which is wider than the 280–300px column spacing this product has always drawn
at — so every ordinary pair of adjacent nodes read as overlapping and the pass
rearranged perfectly good flows. The question is not "how big is a node" but
"how close is too close, given the spacing already in use".

⚠️ Both axes must be too close before it counts: a branch pair one above the
other is not a collision, and pushing one of them right would misrepresent the
flow as having an extra step.

⚠️ Geometry, not a prompt instruction. "Shift the downstream nodes" has one
right answer, and coordinate arithmetic over a whole graph is exactly what a
model gets subtly wrong — intermittently, silently, and differently every time.
The spec *also* tells it to shift on insertion, which reduces the work, but the
guarantee is here. Tests: `tests/Feature/Flow/FlowLayoutTest.php`.

## The thread belongs to the flow

`flow_assistant_messages`, keyed on `flow_id` — not on the person. It used to
live in the browser and be posted back with every turn, which made it private
to one tab: closing the panel lost it, and a colleague opening the same flow saw
an empty box with no idea what had been asked or why the flow looks the way it
does. A flow is shared, so the reasoning behind it is too.

- `GET|DELETE /api/flows/{id}/assistant/messages` — read, or clear.
- `user_id` is who spoke, not who may read, and is nullable: the row outlives
  the account.
- Each assistant turn stores its blueprint, so an old proposal can be put back
  on the canvas ("Show on canvas") without paying for a second run.
- Only the last `HISTORY_TURNS` (12) are replayed to the model, and **never the
  blueprints** — they are enormous, stale the moment the flow changes, and the
  current flow is sent separately every turn.

## Media from the library

The composer has a paperclip that opens the same `GalleryPickerModal` the chat
composer uses. Picked files travel as **`gallery_asset_ids`**, and the server
resolves them to URLs — the same rule every send route follows
(`GalleryMediaResolver`). A URL the browser supplied is a URL the browser chose,
and this one gets written into a saved flow that keeps sending it for months.

The prompt lists them as their own section with an instruction to copy the `url`
values verbatim into `attachment_url` (with the matching `message_type`) or a
carousel card's `header_url`. A model that invents a media URL produces a flow
that saves perfectly and fails at send time, in front of a customer.

## Calling an API from a flow

Yes — that is the `http_request` node, and the specification carries worked GET
and POST examples: headers, a JSON body as a string (`{{variable}}` interpolated
into it), `response_mappings` turning the response into variables the later
nodes read, and both `success`/`error` branches wired. Give the assistant an
endpoint and say what it is for. It is told never to guess a credential: if a
token is needed and was not supplied, it leaves a placeholder and names the node
to open.

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
