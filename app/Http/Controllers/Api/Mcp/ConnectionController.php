<?php

namespace App\Http\Controllers\Api\Mcp;

use App\Http\Controllers\Controller;
use App\Http\Resources\McpConnectionResource;
use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Services\Mcp\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Seeing and cutting MCP connections.
 *
 * ⚠️ Who sees what is the one interesting decision here. A person always sees
 * their own. Someone who can manage agents sees the whole workspace's — because
 * an MCP connection is a standing credential acting as one of their people, and
 * a manager who cannot see one cannot answer "what is connected to our
 * workspace", which is the question an audit asks first.
 *
 * Revoking is allowed on anything visible, and needs no second permission: the
 * safe direction is always off, and someone who can see a connection they think
 * is wrong should not have to find somebody else to end it.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return McpConnectionResource::collection(
            $this->visible($request)->with('user:id,name')->latest('id')->get()
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var McpConnection|null $connection */
        $connection = $this->visible($request)->whereKey($id)->first();

        abort_if(! $connection, 404);

        $connection->revoke();

        AuditLog::record(
            'mcp.connection.revoked',
            "Disconnected \"{$connection->client_name}\" from workspace #{$connection->tenant_id}",
            [
                'tenant_id' => $connection->tenant_id,
                'mcp_connection_id' => $connection->id,
                'owner_id' => $connection->user_id,
            ],
            $request->user(),
        );

        return response()->json(['message' => 'Disconnected.']);
    }

    /** The scope vocabulary, so the consent screen and this page name the same things. */
    public function scopes(): JsonResponse
    {
        return response()->json(['data' => Scopes::all()]);
    }

    private function visible(Request $request)
    {
        $user = $request->user();

        $query = McpConnection::active()->where('tenant_id', $user->tenant_id);

        if (! $user->can('agents.view')) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }
}
