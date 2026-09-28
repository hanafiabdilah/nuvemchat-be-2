<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Media\McpMediaUploads;
use App\Services\Mcp\Scopes;
use App\Services\Media\PublishedUpload;

/**
 * A one-time URL to send a local file to, for a client that can run a shell.
 *
 * This is how a real file gets in. MCP arguments are JSON the model writes, so
 * the only other way to move a file on the person's disk is for the model to
 * type it out as base64 — tokens by the hundred thousand for an ordinary
 * picture. A link lets the client's own curl carry the bytes, and the model
 * only ever sees the answer.
 */
class CreateUploadLinkTool extends Tool
{
    public function __construct(
        private readonly McpMediaUploads $media,
    ) {}

    public function name(): string
    {
        return 'create_upload_link';
    }

    public function title(): string
    {
        return 'Create an upload link';
    }

    public function description(): string
    {
        $ttl = (int) config('mcp.upload_link_ttl_minutes', 15);
        $max = (int) (PublishedUpload::MAX_KB / 1024);

        return 'Get a one-time URL for uploading a file from the local disk, for use in a flow (the same storage as '
            .'the flow builder\'s own uploads). '
            .'Then run the returned curl command (multipart field "file"); its JSON answer holds the file\'s '
            ."permanent \"url\". The link works once and expires after {$ttl} minutes; files up to {$max} MB. "
            .'Needs no credentials of its own, so do not add an Authorization header. '
            .'Use this for local files; use upload_file for a file that is already at a public URL.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::FLOWS_WRITE;
    }

    public function permission(): string
    {
        return 'flows.update';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $link = $this->media->issueLink($connection, $user);

        return ToolResult::data(
            $link + ['method' => 'POST', 'field' => 'file', 'curl' => 'curl -sS -F "file=@/path/to/file" '.escapeshellarg($link['url'])],
            "Upload link ready (one use, expires {$link['expires_at']}). Send the file with:\n"
                .'curl -sS -F "file=@/path/to/file" '.escapeshellarg($link['url']),
        );
    }
}
