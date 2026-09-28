# MCP server (`/mcp`)

Lets a workspace drive Pingly from an LLM client — Claude Code, Claude Desktop,
Codex — over the Model Context Protocol. Phase one exposes the flow builder and
the media gallery.

This is the platform's first surface where an outside program acts as **a named
person** rather than as the workspace. The public API (`/v1/*`) authenticates
with a workspace key that has no permission model at all: one string, full
workspace. That is the right shape for a server-to-server integration and the
wrong one here, because an editor on somebody's laptop should be able to do what
*they* can do and nothing more.

Code: `App\Services\Mcp\*`, `App\Http\Controllers\Mcp\*`, `routes/mcp.php`.

| Piece | Where | Why it is there |
|---|---|---|
| MCP endpoint | `POST /mcp` | One path, stateless, JSON-RPC. No session id, no event stream — a flow tool is one database round trip. |
| Discovery | `/.well-known/oauth-protected-resource[/mcp]`, `/.well-known/oauth-authorization-server` | RFC 9728 + RFC 8414. How a client that knows only the endpoint finds its way to a token. |
| Registration | `POST /mcp/oauth/register` | RFC 7591. Open by design — a client_id is public and confers nothing until a person approves it. |
| Token | `POST /mcp/oauth/token`, `POST /mcp/oauth/revoke` | Authorization code + PKCE, and rotating refresh. |
| Consent screen | `/oauth/mcp/authorize` — **a route in the SPA** | The dashboard holds a bearer token and sends no cookies, so a server-rendered page here would have no session to read. |
| Dashboard | `GET|DELETE /api/mcp/connections`, Developer › AI tools (MCP) | Where a person sees what they approved and ends it. |

---

## The three gates

Every tool call passes all three, in this order:

1. **Plan feature `mcp`** on the workspace (and the feature the tool's own
   surface needs — `flow`, today).
2. **OAuth scope** the person granted this editor: `mcp:flows.read`,
   `mcp:flows.write`.
3. **The person's own Spatie permission**, read live — `flows.view`,
   `flows.update`, `flows.create`, `flows.delete`.

⚠️ The third is the point of the whole design. A scope is a decision the person
made once about an editor; a permission is a decision the workspace makes
continuously about them. Take the flow role away in the dashboard and the next
tool call fails, with nobody having touched the connection.

⚠️ Scopes **narrow, never widen**. `mcp.connect` lets somebody hand out a
credential that acts as them; it does not let them hand out more than they have.

`tools/list` is filtered by all three rather than merely enforced, because a
model shown a tool it cannot use will call it and spend a turn on an error
nobody can act on.

---

## Tools

| Tool | Scope | Permission |
|---|---|---|
| `list_flows` | read | `flows.view` |
| `get_flow` | read | `flows.view` |
| `get_flow_specification` | read | `flows.view` |
| `validate_flow` | read | `flows.view` |
| `create_flow` | write | `flows.create` |
| `update_flow` | write | `flows.update` |
| `delete_flow` | write | `flows.delete` |
| `list_files` | `mcp:media.read` | `gallery.view` |
| `upload_file` | write | `flows.update` |
| `create_upload_link` | write | `flows.update` |

⚠️ **`get_flow_specification` is the one that makes the rest work.** Half of
`FlowBlueprint`'s rules are tenant-scoped `exists` checks — a tag, an agent, an
integration — and there is no way to guess one of those ids. It serves
`FlowBlueprint::specification()` (the same ~17 KB the in-app assistant is
prompted with, generated from the constants the validator enforces) plus
`FlowVocabulary::forTenant()`. Both are shared with the assistant rather than
restated; two descriptions of what a valid flow is would diverge at the next
node type, and the symptom would be a model confidently producing flows that
will not save.

**`create_flow` is strict, `update_flow` is not**, and the asymmetry is
deliberate. A new flow's every problem is one the call just made, so it is held
to `structureProblems` — the assistant's standard, which catches the failures
that *save perfectly and then never run* (a branch value the engine cannot
produce, a step nothing reaches). A flow being edited may have carried such a
problem since long before an editor was involved; refusing there would mean
older flows cannot be touched at all, so it saves and reports `warnings`.

**Editing is whole-graph.** A node left out of `update_flow` is deleted, exactly
as in the builder's own save. `update_flow` returns `node_keys`, the caller's
keys mapped to stored ids — a caller that discards it re-sends its own keys next
time, creating the nodes again and deleting the rows any live conversation is
standing on.

### Media: uploading for flows, reusing the gallery

**Uploads land exactly where the flow builder's own do** — `uploads/` on the
published disk, through `App\Services\Media\PublishedUpload`, the class
`POST /api/uploads` now also uses. Same content-based allow-list, same 10 MB
ceiling, same customer-facing filename, same permanent URL, **no gallery quota**.
So they are gated like editing a flow (`mcp:flows.write` + `flows.update`): a
person who can attach a picture to a node in the builder can do it from an
editor, and nothing here is stricter or looser. ⚠️ The HTTP route itself cannot
be reused — it takes a Sanctum token, and an MCP token must never be one — which
is why the storing is shared as a class instead.

**The gallery is read-only from here.** `list_files` (`mcp:media.read` +
`gallery.view`, plan feature `mcp` only) lists what people uploaded by hand in
Pingly › Gallery, with each file's permanent signed URL, so a flow can use it
as `attachment_url`. Nothing is written into, renamed in or deleted from it.

Three ways to upload, because clients can do different things:

- **`create_upload_link`** — a one-time URL (`POST /mcp/uploads/{token}`,
  multipart field `file`) the client's shell sends a local file to with curl.
  ⚠️ The one that matters: tool arguments are JSON the model *types*, so base64
  of an ordinary picture is hundreds of thousands of tokens. The token lives
  only as a hash in the cache, is single-use (`Cache::pull`, burned even when
  the file is refused), expires after `MCP_UPLOAD_LINK_TTL_MINUTES` (15), and
  grants nothing by itself — redeeming it re-checks the connection, the person,
  the plan and the permission. `throttle:mcp-upload` (30/min per IP).
- **`upload_file` with `url`** — fetched through `PublicUrl` + `OutboundHttp`
  like every caller-chosen address (public hosts only, redirects re-checked,
  streamed to disk under the 10 MB ceiling).
- **`upload_file` with `content_base64`** — small files, capped at
  `MCP_UPLOAD_INLINE_MAX_MB` (5).

⚠️ Connections approved before `mcp:media.read` existed cannot see the gallery
until the person reconnects; uploading needs no new scope. `/mcp/*` is already
in Caddy's `@backend`, so no Caddy change.

---

## Deploying

### 1. Caddy first

`/mcp` and `/.well-known/oauth-*` are in `@backend` on the platform host and in
`@platform_only` on country domains. ⚠️ **Without the first, `/mcp` is served
the SPA's `index.html` with status 200**, which an MCP client reports as a JSON
parse error rather than a missing route — the same failure that swallowed the
TikTok callback until Sep 2026.

```bash
./deploy.sh caddy
./deploy.sh backend      # never the other way round
```

### 2. Migrations

`deploy.sh` runs them. Two: `2026_09_27_000100_create_mcp_tables` and
`2026_09_27_000200_add_mcp_permission`, which gives `mcp.connect` to existing
owner roles (deploys only run `migrate --force`, so the seeder alone would not
reach them).

### 3. Sell it

`mcp` is a plan feature. Nothing is reachable until it is switched on for a plan
in **Back Office → Plans**; plans created before this exist without it.

### 4. Switch it on

With `MCP_ENABLED=false` every path here answers **404**, which is the correct
state and not an outage: a client that gets 404 from the discovery document
concludes this server does not offer MCP and says so, whereas a valid document
followed by a 503 leads somebody halfway through an authorization they cannot
finish.

```bash
# /opt/pingly/.env
MCP_ENABLED=true
```

⚠️ `env_file` is only read when a container starts, so
`docker compose up -d --force-recreate app app-2 queue` (and the rest of the PHP
containers) — a `config:cache` alone will not pick it up.

---

## Verifying

```bash
# Must answer JSON, not HTML. If you get <!doctype html>, step 1 was skipped.
curl -s https://chat.pingly.com.br/.well-known/oauth-protected-resource | head -c 200

# Must be 401 with a WWW-Authenticate naming the metadata document.
curl -si -X POST https://chat.pingly.com.br/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | head -12

# Must be 404 on a country domain.
curl -s -o /dev/null -w '%{http_code}\n' https://chat.pingly.id/mcp
```

End to end, from a machine with Claude Code:

```bash
claude mcp add --transport http pingly https://chat.pingly.com.br/mcp
# → opens a browser → sign in → the consent screen → approve
claude mcp list
```

---

## Things worth knowing before they surprise someone

**Consent always happens on the platform host.** OAuth allows one issuer per
protected resource, so `authorization_endpoint` cannot follow a country domain.
Somebody who signs in at `chat.pingly.id` is sent to `chat.pingly.com.br` to
approve, and signs in there once. Their account works on both — it is the same
account — but the hop is visible.

**Browser-based clients are not supported.** `/mcp` refuses a request carrying
an `Origin` that is not ours, and the path is not in `config/cors.php`. Native
clients (Claude Code, Claude Desktop, Codex) send no Origin and are unaffected.
Relaxing this is a decision, not an oversight.

**`SanitizeUpstreamErrors` is deliberately not on this route group.**
`invalid_grant` and `invalid_client` are on its fingerprint list — they were put
there because *other systems'* OAuth errors used to leak through — and here they
are our own protocol vocabulary, the field a client branches on. Do not add it
back.

**A code presented twice takes down what it produced.** OAuth 2.1 asks the
server to assume a replay, and it does: the connection is revoked. The
legitimate client rebuilds it in one redirect; a thief loses the tokens. The
same applies to a rotated refresh token used a second time.

**Tokens are not Sanctum tokens** and must never become them. Nothing in the
~388 routes under `/api` calls `tokenCan()`, so a Sanctum token minted here with
scope `mcp:flows.read` would be accepted in full on every dashboard endpoint.

---

## Rolling back

`MCP_ENABLED=false` and recreate the PHP containers. Existing tokens stop being
accepted; nothing is deleted, so switching it back on restores every connection.
To end connections for good, revoke them in the dashboard or
`McpConnection::active()->get()->each->revoke()`.

Tests: `tests/Feature/Mcp/` (discovery, authorization, transport, flow tools),
plus the MCP prefixes in `tests/Feature/Market/PlatformOnlyRoutesTest.php`.
