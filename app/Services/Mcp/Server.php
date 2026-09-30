<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Tools\Tool;
use App\Services\Mcp\Tools\ToolException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The JSON-RPC surface at POST /mcp.
 *
 * Stateless: no session id, no standing stream, one HTTP request per message.
 * That is the shape revision 2026-07-28 settled on, and it happens to be the
 * only shape that suits what this server does — a flow tool is one database
 * round trip, so there is never a long-running call to report progress on.
 *
 * ⚠️ Dual-era, deliberately. Revision 2026-07-28 dropped the `initialize`
 * handshake and carries version and client identity in each request's `_meta`;
 * every revision before it opens with `initialize`. Claude Code moved; Claude
 * Desktop and others have not. A server that spoke only the new one would
 * answer half the clients with a protocol error they cannot act on, which reads
 * as a broken product rather than an old client. The two eras share every
 * method below — only the envelope differs.
 */
final class Server
{
    /** The first revision with per-request metadata; anything at or past it is "modern". */
    public const MODERN = '2026-07-28';

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    /** Header/body mismatch (MCP-allocated). */
    private const ERR_HEADER_MISMATCH = -32020;

    /** Unsupported protocol version (MCP-allocated). */
    private const ERR_UNSUPPORTED_VERSION = -32022;

    private const ERR_METHOD_NOT_FOUND = -32601;

    private const ERR_INVALID_PARAMS = -32602;

    private const ERR_INTERNAL = -32603;

    public function __construct(
        private ToolRegistry $tools,
    ) {}

    /**
     * @return array{status: int, body: array|null}
     */
    public function handle(Request $request, McpConnection $connection, User $user): array
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || ! is_string($payload['method'] ?? null)) {
            return $this->error(null, self::ERR_INVALID_PARAMS, 'Expected a single JSON-RPC request object.', status: 400);
        }

        $method = $payload['method'];
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $id = $payload['id'] ?? null;

        // A notification has no id and gets no reply — only an acknowledgement.
        // Nothing in this server's vocabulary needs one, but a legacy client
        // sends `notifications/initialized` right after the handshake and would
        // otherwise read a body as a protocol violation.
        if (! array_key_exists('id', $payload)) {
            return ['status' => 202, 'body' => null];
        }

        $version = $params['_meta'][self::META_VERSION] ?? null;
        $modern = is_string($version) && $version >= self::MODERN;

        if ($problem = $this->versionProblem($request, $modern ? $version : null, $method, $params)) {
            return $this->error($id, $problem['code'], $problem['message'], $problem['data'] ?? null, 400);
        }

        try {
            return match ($method) {
                'server/discover' => $this->result($id, $this->discover(), $modern),
                'initialize' => $this->result($id, $this->initialize($params), false),
                'ping' => $this->result($id, [], $modern),
                'tools/list' => $this->result($id, $this->listTools($connection, $user), $modern),
                'tools/call' => $this->result($id, $this->callTool($params, $connection, $user), $modern),
                default => $this->error($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$method}", status: 404),
            };
        } catch (ToolException $e) {
            // Business feedback, not a protocol failure — see ToolException.
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->text()]],
                'isError' => true,
            ], $modern);
        } catch (Throwable $e) {
            // The message is ours or it is not shown. An exception from deep in
            // the flow stack can name a column, a class or somebody's data.
            Log::error('MCP: a tool call failed', [
                'method' => $method,
                'tenant_id' => $connection->tenant_id,
                'mcp_connection_id' => $connection->id,
                'exception' => $e,
            ]);

            return $this->error($id, self::ERR_INTERNAL, 'Something went wrong on our side handling this call.');
        }
    }

    /**
     * Protocol version and the header mirror.
     *
     * Modern clients repeat `method`, the protocol version and (for a tool
     * call) the tool name in headers so that proxies can route without reading
     * the body. That only helps if the two agree, so the server compares them —
     * otherwise a load balancer routing on the header and this server acting on
     * the body could be looking at two different requests.
     *
     * @return array{code: int, message: string, data?: array}|null
     */
    private function versionProblem(Request $request, ?string $bodyVersion, string $method, array $params): ?array
    {
        $supported = (array) config('mcp.protocol_versions');
        $header = $request->header('MCP-Protocol-Version');

        if ($bodyVersion === null) {
            // Legacy era. The header appeared in 2025-06-18; before that there
            // was none, and its absence is read as the oldest revision we serve.
            if ($header !== null && ! in_array($header, $supported, true)) {
                return $this->unsupported($header, $supported);
            }

            return null;
        }

        if (! in_array($bodyVersion, $supported, true)) {
            return $this->unsupported($bodyVersion, $supported);
        }

        if ($header !== $bodyVersion) {
            return [
                'code' => self::ERR_HEADER_MISMATCH,
                'message' => 'Header mismatch: MCP-Protocol-Version does not match the version in _meta.',
            ];
        }

        if ($request->header('Mcp-Method') !== $method) {
            return [
                'code' => self::ERR_HEADER_MISMATCH,
                'message' => 'Header mismatch: Mcp-Method does not match the method in the body.',
            ];
        }

        if ($method === 'tools/call') {
            $name = (string) ($params['name'] ?? '');
            $sent = $this->decodeHeaderValue($request->header('Mcp-Name'));

            if ($sent !== $name) {
                return [
                    'code' => self::ERR_HEADER_MISMATCH,
                    'message' => 'Header mismatch: Mcp-Name does not match params.name.',
                ];
            }
        }

        return null;
    }

    /** @return array{code: int, message: string, data: array} */
    private function unsupported(string $requested, array $supported): array
    {
        return [
            'code' => self::ERR_UNSUPPORTED_VERSION,
            'message' => 'Unsupported protocol version',
            'data' => ['supported' => array_values($supported), 'requested' => $requested],
        ];
    }

    /**
     * A header value may arrive Base64-wrapped when it cannot be written as
     * plain ASCII. Tool names here never need it, but the comparison has to
     * decode before it compares or a conforming client gets a mismatch for
     * doing the right thing.
     */
    private function decodeHeaderValue(?string $value): string
    {
        $value = (string) $value;

        if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
            return (string) base64_decode(substr($value, 9, -2), true);
        }

        return $value;
    }

    private function discover(): array
    {
        return [
            'supportedVersions' => array_values((array) config('mcp.protocol_versions')),
            'capabilities' => ['tools' => new \stdClass],
            'instructions' => $this->instructions(),
            '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()],
        ];
    }

    private function initialize(array $params): array
    {
        $supported = (array) config('mcp.protocol_versions');
        $asked = (string) ($params['protocolVersion'] ?? '');

        // Echo what they asked for when we speak it, otherwise the newest
        // handshake-era revision — never 2026-07-28, which a client sending
        // `initialize` by definition does not speak.
        $legacy = array_values(array_filter($supported, fn (string $v) => $v < self::MODERN));

        return [
            'protocolVersion' => in_array($asked, $legacy, true) ? $asked : ($legacy[0] ?? '2025-06-18'),
            'capabilities' => ['tools' => new \stdClass],
            'serverInfo' => $this->serverInfo(),
            'instructions' => $this->instructions(),
        ];
    }

    private function serverInfo(): array
    {
        return [
            'name' => (string) config('mcp.server_name'),
            'version' => (string) config('mcp.server_version'),
        ];
    }

    /**
     * Read by the model before it does anything, so it says the two things that
     * actually prevent wasted turns here: read the format first, and edit by
     * sending the whole graph back.
     */
    private function instructions(): string
    {
        return <<<'TEXT'
        Pingly is an omnichannel messaging platform. This server exposes its flow
        builder (the automations that answer customers on WhatsApp, Instagram,
        Telegram and the rest), its broadcast campaigns, its statistics and live
        monitor, and its sales funnel of leads. Which of these you can reach
        depends on what the person allowed and on their role; the tool list
        already reflects that.

        Before writing or changing any flow, call `get_flow_specification` once. It
        returns the exact node format this workspace accepts, together with the real
        tags, agents, AI agents and integrations you may reference by id. A flow that
        names anything not in that list will be refused.

        Editing is whole-graph, not incremental: call `get_flow`, change what you
        need in the result, and send all of it back to `update_flow`. A node left out
        is deleted. Keep each node's `key` as it came back, or you will replace nodes
        that running conversations are standing on.

        `validate_flow` costs nothing and writes nothing. Use it before `create_flow`
        or `update_flow` whenever you are unsure.

        A message can carry an image, video, audio or document. If the person means a
        file they already have, look in their media gallery with `list_files` and use
        its `url`. To add a new file from the local disk, call `create_upload_link`
        and send it with the curl command it returns; for a file already online, pass
        its URL to `upload_file`. Either way the `url` you get back is permanent: use
        it as a message's `attachment_url`, with `message_type` equal to its `type`.

        Campaigns can be read, paused, resumed, canceled and have their failures
        retried, but not created or started here. Resuming and retrying send
        messages to customers, and canceling cannot be undone: confirm with the
        person before any of them.

        Statistics and the live monitor are read-only. Statistics take calendar
        days (YYYY-MM-DD) and a timezone; pass the person's timezone, or "today"
        will start on the server's clock. Filter ids come from
        `get_statistics_filters`.

        For leads, call `list_lead_pipelines` first: stages and owners are
        referred to by id. A contact can have only one open lead; find the
        contact with `find_contacts` before `create_lead`.
        TEXT;
    }

    private function listTools(McpConnection $connection, User $user): array
    {
        return [
            'tools' => array_map(
                fn (Tool $tool) => $tool->definition(),
                $this->tools->availableTo($connection, $user),
            ),
        ];
    }

    private function callTool(array $params, McpConnection $connection, User $user): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = $this->tools->find($name);

        if (! $tool) {
            throw new ToolException("There is no tool called \"{$name}\" on this server.");
        }

        if (! $this->tools->permits($tool, $connection, $user)) {
            throw new ToolException($this->tools->refusalFor($tool, $connection, $user));
        }

        $result = $tool->run($arguments, $connection, $user);

        return array_filter([
            'content' => [['type' => 'text', 'text' => $result->text]],
            'structuredContent' => $result->structured,
            'isError' => false,
        ], fn ($value) => $value !== null);
    }

    /** @return array{status: int, body: array} */
    private function result(mixed $id, array $result, bool $modern): array
    {
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result;
        }

        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    /** @return array{status: int, body: array} */
    private function error(mixed $id, int $code, string $message, ?array $data = null, int $status = 200): array
    {
        $error = array_filter([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], fn ($value) => $value !== null);

        return ['status' => $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]];
    }
}
