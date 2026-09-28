<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Gallery\AssetType;
use App\Models\GalleryAsset;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Media\McpMediaUploads;
use App\Services\Mcp\Scopes;

/**
 * What is already in the workspace's media gallery — files a person uploaded
 * by hand in Pingly › Gallery — so a flow can use them.
 *
 * Read-only: MCP never writes into the gallery (uploads go where the flow
 * builder's do). Listed before uploading for a reason: the picture a person
 * wants in a flow is often one they already put there, and a gallery URL is
 * permanent, so reusing it is both cheaper and what they meant.
 */
class ListFilesTool extends Tool
{
    public function __construct(
        private readonly McpMediaUploads $media,
    ) {}

    public function name(): string
    {
        return 'list_files';
    }

    public function title(): string
    {
        return 'List gallery files';
    }

    public function description(): string
    {
        return 'List the files people uploaded to this workspace\'s media gallery in Pingly (images, videos, audio, '
            .'documents), newest first, with the permanent URL of each. When the person refers to a file they already '
            .'have ("our catalogue", "the logo"), look here before uploading anything. Use a file\'s "url" as a flow '
            .'message\'s attachment_url (or a carousel card\'s header_url), with message_type equal to the file\'s "type".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Only files whose name contains this.'],
                'type' => ['type' => 'string', 'enum' => AssetType::values(), 'description' => 'Only files of this kind.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'How many to return. Default 30.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of results, starting at 1.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::MEDIA_READ;
    }

    public function permission(): string
    {
        return 'gallery.view';
    }

    public function feature(): Feature
    {
        return Feature::Mcp;
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $type = $arguments['type'] ?? null;
        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 30)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        if ($type !== null && ! in_array($type, AssetType::values(), true)) {
            throw new ToolException('"type" must be one of: '.implode(', ', AssetType::values()).'.');
        }

        $assets = GalleryAsset::forTenant($user->tenant_id)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return ToolResult::data(
            [
                'files' => collect($assets->items())->map(fn (GalleryAsset $a) => $this->media->describe($a))->values()->all(),
                'page' => $assets->currentPage(),
                'has_more' => $assets->hasMorePages(),
                'total' => $assets->total(),
            ],
            $assets->total().' file(s) in the gallery; showing page '.$assets->currentPage().'.',
        );
    }
}
