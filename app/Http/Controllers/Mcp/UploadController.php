<?php

namespace App\Http\Controllers\Mcp;

use App\Enums\Billing\Feature;
use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Services\Billing\SubscriptionGate;
use App\Services\Mcp\Media\McpMediaUploads;
use App\Services\Mcp\ToolRegistry;
use App\Services\Mcp\Tools\CreateUploadLinkTool;
use App\Services\Mcp\Tools\ToolException;
use App\Services\Mcp\Tools\UploadFileTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Where an upload link from create_upload_link is redeemed.
 *
 * ⚠️ The link is only a way to find the grant, never the grant itself. Between
 * issuing it and this request the person can lose the gallery role, the editor
 * can be disconnected or the workspace suspended — so everything a tool call
 * checks is checked again here, against the rows as they are now.
 */
class UploadController extends Controller
{
    public function __construct(
        private readonly McpMediaUploads $media,
        private readonly ToolRegistry $tools,
        private readonly SubscriptionGate $gate,
    ) {}

    public function store(Request $request, string $token): JsonResponse
    {
        $claims = $this->media->redeemLink($token);

        if ($claims === null) {
            return $this->refuse('link_invalid', 'This upload link is not valid, was already used, or has expired. Ask for a new one with create_upload_link.', 404);
        }

        $connection = McpConnection::with(['user', 'tenant'])->find($claims['mcp_connection_id']);
        $user = $connection?->user;

        if (! $connection || ! $connection->isActive() || ! $user
            || $user->id !== (int) $claims['user_id'] || $user->tenant_id !== $connection->tenant_id) {
            return $this->refuse('connection_unavailable', 'The connection that created this link is no longer available.', 403);
        }

        if (config('services.billing.enforce')
            && (! $this->gate->usable($connection->tenant) || ! $this->gate->feature($connection->tenant, Feature::Mcp->value))) {
            return $this->refuse('access_denied', 'This workspace can no longer use MCP.', 403);
        }

        $tool = app(CreateUploadLinkTool::class);

        if (! $this->tools->permits($tool, $connection, $user)) {
            return $this->refuse('access_denied', $this->tools->refusalFor($tool, $connection, $user), 403);
        }

        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            return $this->refuse('file_missing', 'Send the file as multipart form data in a field named "file" (curl -F "file=@/path/to/file").', 422);
        }

        Auth::setUser($user);

        try {
            $result = UploadFileTool::stored($this->media, $file, $connection, $user, 'link');
        } catch (ToolException $e) {
            return $this->refuse('upload_refused', $e->text(), 422);
        } catch (\Throwable $e) {
            Log::error('MCP: an upload through a link failed', [
                'tenant_id' => $connection->tenant_id,
                'mcp_connection_id' => $connection->id,
                'exception' => $e,
            ]);

            return $this->refuse('server_error', 'Something went wrong on our side storing this file.', 500);
        }

        $connection->recordUse($request->ip());

        return response()->json($result->structured, 201);
    }

    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
