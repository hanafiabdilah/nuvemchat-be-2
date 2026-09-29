<?php

namespace App\Services\Mcp\Media;

use App\Enums\Gallery\AssetType;
use App\Models\GalleryAsset;
use App\Enums\Media\UploadConflict;
use App\Models\McpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mcp\Tools\ToolException;
use App\Services\Media\PublishedUpload;
use App\Services\Media\UploadPolicy;
use App\Support\OutboundHttp;
use App\Support\PublicUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Files an MCP client uploads for use in flows, and the gallery it may reuse.
 *
 * ⚠️ Uploads land exactly where the flow builder's own do — `uploads/{tenant}`
 * on the published disk, through `PublishedUpload`, the class `POST /api/uploads`
 * uses. Same folder, same allow-list, same 10 MB ceiling, same permanent URL,
 * no quota. A
 * person who can attach a picture to a node in the builder can do the same
 * from an editor, and nothing here is stricter or looser than that.
 *
 * The gallery is read-only from here: files a person uploaded by hand in
 * Pingly › Gallery can be listed and their URL used in a flow, but nothing is
 * written into it.
 *
 * Three ways in, because the clients that matter can do different things:
 *
 *  - an upload link (`issueLink`): a one-time URL the client's shell sends the
 *    file to with curl. The only practical way for a real file — a model has to
 *    *type* base64 token by token, and a 500 KB picture is most of a context
 *    window.
 *  - base64 inline: for a client with no shell and a small file.
 *  - a public URL the server fetches: for a file that is already online.
 */
class McpMediaUploads
{
    private const LINK_CACHE_PREFIX = 'mcp-upload-link:';

    /**
     * Validate and store, describing the result.
     *
     * @return array{url: string, filename: string, type: string, mime_type: string|null, size_bytes: int}
     *
     * @throws ToolException with a sentence the caller can act on
     */
    public function store(UploadedFile $file, Tenant $tenant, ?UploadConflict $onConflict = null): array
    {
        $validator = Validator::make(['file' => $file], ['file' => PublishedUpload::rules()], [
            'file.mimes' => UploadPolicy::message(),
        ]);

        if ($validator->fails()) {
            throw new ToolException('The file was not accepted.', $validator->errors()->all());
        }

        // ⚠️ `rename` by default, unlike the dashboard. There is nobody at the
        // prompt to answer a collision: failing the call spends a turn on a
        // name clash, and replacing could repoint a flow node the model was
        // never asked to touch. Keeping both is the only answer that is always
        // safe and always succeeds. A caller who means to replace says so.
        $stored = PublishedUpload::store($file, $tenant, $onConflict ?? UploadConflict::Rename);

        return [
            'url' => $stored['url'],
            'filename' => $stored['filename'],
            'type' => AssetType::classify($stored['mime_type'], pathinfo($stored['path'], PATHINFO_EXTENSION))->value,
            'mime_type' => $stored['mime_type'],
            'size_bytes' => (int) $stored['size'],
        ];
    }

    /**
     * Base64 the model wrote into the call. Strict decoding: a string that is
     * only mostly base64 would otherwise be stored as a corrupt file that
     * uploads "successfully" and then fails at the moment a customer opens it.
     */
    public function fromBase64(string $content, string $filename): UploadedFile
    {
        // A data URI is what a model most often produces when asked for base64.
        if (preg_match('/^data:[^;,]*;base64,/i', $content, $m)) {
            $content = substr($content, strlen($m[0]));
        }

        $content = preg_replace('/\s+/', '', $content) ?? '';
        $maxBytes = $this->inlineMaxBytes();

        // Checked on the encoded length first so a huge string is refused
        // before it is decoded into a second copy in memory.
        if ((int) floor(strlen($content) * 3 / 4) > $maxBytes) {
            throw new ToolException(sprintf(
                'Inline content is limited to %s. For a larger file call create_upload_link and send it with curl, or pass a public "url" instead.',
                self::formatBytes($maxBytes),
            ));
        }

        $bytes = base64_decode($content, true);

        if ($bytes === false || $bytes === '') {
            throw new ToolException('"content_base64" is not valid base64.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $this->cleanFilename($filename), null, null, true);
    }

    /**
     * A file that is already online. Fetched through the same guard as every
     * other address a caller chooses — public hosts only, every redirect
     * re-checked, streamed to disk under a ceiling.
     */
    public function fromUrl(string $url, ?string $filename = null): UploadedFile
    {
        if (! PublicUrl::isFetchable($url)) {
            throw new ToolException('That URL cannot be fetched. It must be a public http(s) address.');
        }

        $maxBytes = PublishedUpload::MAX_KB * 1024;
        $path = tempnam(sys_get_temp_dir(), 'mcp_');

        try {
            $response = OutboundHttp::guard(OutboundHttp::pinHost(Http::timeout(30), $url), $maxBytes)
                ->sink($path)
                ->get($url);
        } catch (\Throwable $e) {
            @unlink($path);
            Log::info('MCP upload: fetching a URL failed', ['host' => parse_url($url, PHP_URL_HOST), 'error' => $e->getMessage()]);

            throw new ToolException('The file at that URL could not be downloaded (unreachable, redirected somewhere private, or larger than '.self::formatBytes($maxBytes).').');
        }

        if (! $response->successful()) {
            @unlink($path);

            throw new ToolException("The URL answered HTTP {$response->status()}, so there was nothing to upload.");
        }

        if (filesize($path) > $maxBytes) {
            @unlink($path);

            throw new ToolException('The file at that URL is larger than '.self::formatBytes($maxBytes).'.');
        }

        $name = $filename !== null && trim($filename) !== ''
            ? $filename
            : rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return new UploadedFile($path, $this->cleanFilename($name), null, null, true);
    }

    /**
     * A one-time address the client's shell can send one file to.
     *
     * The token is the credential, so it is long, lives only in the cache as a
     * hash, and dies after one use or `upload_link_ttl_minutes`. It carries no
     * rights of its own: redeeming it re-reads the connection, the person and
     * their permission, exactly as a tool call would.
     *
     * @return array{url: string, expires_at: string, expires_in_seconds: int}
     */
    public function issueLink(McpConnection $connection, User $user, ?UploadConflict $onConflict = null): array
    {
        $token = Str::random(48);
        $ttl = max(1, (int) config('mcp.upload_link_ttl_minutes', 15)) * 60;

        // The answer to a name collision is decided when the link is asked for,
        // not when curl redeems it: the client that knows what it means to do is
        // the one talking to us now, and the redeem request is a bare file post.
        Cache::put(self::LINK_CACHE_PREFIX.hash('sha256', $token), [
            'mcp_connection_id' => $connection->id,
            'user_id' => $user->id,
            'on_conflict' => ($onConflict ?? UploadConflict::Rename)->value,
        ], $ttl);

        return [
            'url' => url('/mcp/uploads/'.$token),
            'expires_at' => now()->addSeconds($ttl)->toIso8601ZuluString(),
            'expires_in_seconds' => $ttl,
        ];
    }

    /**
     * Take the link's claims out of the cache. `pull`, not `get`: a link is
     * used once, whether or not the file it carried was accepted — a refused
     * upload costs one more create_upload_link call, a reusable link costs a
     * credential that outlives its purpose.
     *
     * @return array{mcp_connection_id: int, user_id: int, on_conflict?: string}|null
     */
    public function redeemLink(string $token): ?array
    {
        if (strlen($token) !== 48) {
            return null;
        }

        $claims = Cache::pull(self::LINK_CACHE_PREFIX.hash('sha256', $token));

        return is_array($claims) ? $claims : null;
    }

    /** A gallery file as list_files reports it: above all its permanent URL. */
    public function describe(GalleryAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'type' => $asset->type->value,
            'mime_type' => $asset->mime_type,
            'size_bytes' => (int) $asset->size_bytes,
            'url' => $asset->publicUrl(),
            'created_at' => $asset->created_at?->toIso8601ZuluString(),
        ];
    }

    public function inlineMaxBytes(): int
    {
        return max(1, (int) config('mcp.upload_inline_max_mb', 5)) * 1024 * 1024;
    }

    public static function formatBytes(int $bytes): string
    {
        return GalleryAsset::formatBytes($bytes);
    }

    /** A name only: whatever the caller sent, never a path. */
    private function cleanFilename(string $filename): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));

        return $name !== '' && $name !== '.' && $name !== '..' ? Str::limit($name, 200, '') : 'arquivo';
    }
}
