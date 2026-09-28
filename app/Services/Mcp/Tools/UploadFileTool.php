<?php

namespace App\Services\Mcp\Tools;

use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Media\McpMediaUploads;
use App\Services\Mcp\Scopes;
use App\Services\Media\PublishedUpload;
use Illuminate\Http\UploadedFile;

/**
 * Upload a file for a flow, from inside the call itself.
 *
 * It lands where the flow builder's own uploads do (see McpMediaUploads), so
 * it is gated like editing a flow: the flows write scope and `flows.update`.
 *
 * Two sources, exactly one per call: base64 the model writes, or a public URL
 * the server fetches. For a file on the person's own disk, create_upload_link
 * is the better road and the description says so — a model typing base64 pays
 * for every byte in tokens.
 */
class UploadFileTool extends Tool
{
    public function __construct(
        private readonly McpMediaUploads $media,
    ) {}

    public function name(): string
    {
        return 'upload_file';
    }

    public function title(): string
    {
        return 'Upload a file for a flow';
    }

    public function description(): string
    {
        $inline = (int) config('mcp.upload_inline_max_mb', 5);
        $max = (int) (PublishedUpload::MAX_KB / 1024);

        return "Upload an image, video, audio or document (up to {$max} MB) and get back its permanent URL, "
            .'the same way the flow builder uploads a message attachment. '
            .'Give exactly one source: "url" (a public http(s) address the server downloads) or "content_base64" '
            ."plus \"filename\" (small files only, up to {$inline} MB). "
            .'For a file on the local disk, prefer create_upload_link when you can run a shell command — it avoids '
            .'encoding the file into this call. If the file may already be in the workspace\'s gallery, check '
            .'list_files first. Use the returned "url" as a flow message\'s attachment_url, with message_type equal to its "type".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'A public http(s) URL of the file.'],
                'content_base64' => ['type' => 'string', 'description' => 'The file\'s bytes, base64-encoded (a data: URI is accepted).'],
                'filename' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The file name with its extension, e.g. "catalogo.pdf". Required with content_base64. Customers see it on documents.'],
            ],
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
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        $content = (string) ($arguments['content_base64'] ?? '');
        $filename = trim((string) ($arguments['filename'] ?? ''));

        if (($url === '') === ($content === '')) {
            throw new ToolException('Give exactly one of "url" or "content_base64".');
        }

        if ($content !== '' && $filename === '') {
            throw new ToolException('"filename" (with its extension) is required with content_base64.');
        }

        $file = $content !== ''
            ? $this->media->fromBase64($content, $filename)
            : $this->media->fromUrl($url, $filename !== '' ? $filename : null);

        return self::stored($this->media, $file, $connection, $user, $url !== '' ? 'url' : 'inline');
    }

    /** Shared with the upload-link endpoint, so both report a file the same way. */
    public static function stored(McpMediaUploads $media, UploadedFile $file, McpConnection $connection, User $user, string $source): ToolResult
    {
        try {
            $stored = $media->store($file);
        } finally {
            @unlink($file->getRealPath() ?: $file->getPathname());
        }

        AuditLog::record(
            'mcp.media.uploaded',
            "Uploaded \"{$stored['filename']}\" from {$connection->client_name}",
            ['tenant_id' => $user->tenant_id, 'url' => $stored['url'], 'mcp_connection_id' => $connection->id, 'source' => $source],
            $user,
        );

        return ToolResult::data(
            ['file' => $stored],
            "Uploaded \"{$stored['filename']}\" ({$stored['type']}).",
        );
    }
}
