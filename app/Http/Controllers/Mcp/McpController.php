<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Services\Mcp\Server;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint itself: one path, POST only, one JSON-RPC message per call.
 */
class McpController extends Controller
{
    public function __construct(
        private Server $server,
    ) {}

    public function handle(Request $request): Response
    {
        // Streamable HTTP requires the server to check Origin, to stop a page
        // in somebody's browser from driving a local MCP server. Ours is not
        // local, and it is bearer-authenticated rather than cookie-
        // authenticated, so a cross-site page has nothing to replay — but a
        // request that arrives with a browser's Origin is not coming from an
        // MCP client, and there is no reason to serve it.
        $origin = $request->header('Origin');

        if ($origin !== null && $origin !== '' && ! $this->isOwnOrigin($origin)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Cross-origin requests are not accepted here.'],
            ], 403);
        }

        /** @var McpConnection $connection */
        $connection = $request->attributes->get('mcp_connection');

        $result = $this->server->handle($request, $connection, $request->user());

        if ($result['body'] === null) {
            return response()->noContent($result['status']);
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * GET and DELETE used to mean something here — a standalone event stream
     * and a session teardown — and revision 2026-07-28 removed both. 405 with
     * the allowed method is what tells an older client to stop asking, and it
     * is what the specification asks a server of this revision to answer.
     */
    public function methodNotAllowed(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'This MCP endpoint accepts POST only.'],
        ], 405)->header('Allow', 'POST');
    }

    private function isOwnOrigin(string $origin): bool
    {
        return rtrim(strtolower($origin), '/') === rtrim(strtolower(url('/')), '/');
    }
}
