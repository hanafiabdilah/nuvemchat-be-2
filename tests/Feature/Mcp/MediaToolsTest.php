<?php

use App\Enums\Gallery\AssetType;
use App\Models\GalleryAsset;
use App\Services\Mcp\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mcp.enabled' => true, 'media.published_disk' => 'public']);
    Storage::fake('public');
});

function mcpPngBytes(): string
{
    // 1×1 transparent PNG.
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

/** A file somebody put in the gallery by hand, in the dashboard. */
function mcpGalleryAsset(int $tenantId, string $name = 'Catálogo 2026'): GalleryAsset
{
    $uuid = (string) Str::uuid();

    return GalleryAsset::create([
        'tenant_id' => $tenantId,
        'uuid' => $uuid,
        'public_filename' => 'catalogo-2026.pdf',
        'name' => $name,
        'path' => "gallery/{$tenantId}/{$uuid}.pdf",
        'mime_type' => 'application/pdf',
        'type' => AssetType::Document,
        'size_bytes' => 2048,
        'checksum' => hash('sha256', $uuid),
    ]);
}

// ─────────────────────────── Who sees what ───────────────────────────

it('ties uploading to editing flows, and reading the gallery to the gallery', function () {
    $user = McpFixtures::user(['flows.view', 'flows.update', 'gallery.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE, Scopes::MEDIA_READ]);

    expect(collect(McpFixtures::call($this, $token, 'tools/list')->json('result.tools'))->pluck('name'))
        ->toContain('upload_file', 'create_upload_link', 'list_files');

    // Uploading is flow editing: no flows.update, no upload — whatever the
    // gallery permissions say.
    $viewer = McpFixtures::user(['flows.view', 'gallery.view', 'gallery.manage']);
    [, $viewerToken] = McpFixtures::connect($viewer, [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE, Scopes::MEDIA_READ]);

    expect(collect(McpFixtures::call($this, $viewerToken, 'tools/list')->json('result.tools'))->pluck('name'))
        ->toContain('list_files')
        ->not->toContain('upload_file')
        ->not->toContain('create_upload_link');

    // And a connection approved without the gallery scope never reads it.
    [, $flowsOnly] = McpFixtures::connect($user, [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE]);

    expect(collect(McpFixtures::call($this, $flowsOnly, 'tools/list')->json('result.tools'))->pluck('name'))
        ->not->toContain('list_files');
});

// ─────────────────────────── Uploading ───────────────────────────

it('uploads inline base64 where the flow builder uploads, with no gallery quota involved', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $result = McpFixtures::tool($this, $token, 'upload_file', [
        'content_base64' => 'data:image/png;base64,'.base64_encode(mcpPngBytes()),
        'filename' => 'Logo Aurora.png',
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['file']['type'])->toBe('image')
        // Encoded in the URL, pristine on disk: the space is the URL's problem,
        // not the filename's.
        ->and($result['structuredContent']['file']['url'])->toEndWith('/Logo%20Aurora.png')
        ->and($result['structuredContent']['file']['url'])->toContain('/storage/uploads/')
        ->and($result['structuredContent']['file']['filename'])->toBe('Logo Aurora.png');

    expect(Storage::disk('public')->allFiles('uploads'))->toHaveCount(1)
        // Listed in the gallery as a flow file, but nothing is stored there:
        // the quota counts none of it.
        ->and(GalleryAsset::counted()->count())->toBe(0)
        ->and(GalleryAsset::where('origin', 'flow')->count())->toBe(1);
});

it('refuses what the builder\'s upload refuses, whatever the name says', function () {
    [, $token] = McpFixtures::connect(McpFixtures::user());

    $result = McpFixtures::tool($this, $token, 'upload_file', [
        'content_base64' => base64_encode('<html><script>alert(1)</script></html>'),
        'filename' => 'invoice.png',
    ]);

    expect($result['isError'])->toBeTrue()
        ->and(Storage::disk('public')->allFiles('uploads'))->toBe([]);
});

it('refuses a url that points inside the network', function () {
    [, $token] = McpFixtures::connect(McpFixtures::user());

    $result = McpFixtures::tool($this, $token, 'upload_file', ['url' => 'http://169.254.169.254/latest/meta-data']);

    expect($result['isError'])->toBeTrue();
});

it('downloads a public url', function () {
    [, $token] = McpFixtures::connect(McpFixtures::user());

    Http::fake(['https://cdn.example.com/*' => Http::response(mcpPngBytes(), 200, ['Content-Type' => 'image/png'])]);

    $result = McpFixtures::tool($this, $token, 'upload_file', ['url' => 'https://cdn.example.com/img/banner.png']);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['file']['filename'])->toBe('banner.png');
});

it('accepts one file through an upload link, once', function () {
    [, $token] = McpFixtures::connect(McpFixtures::user());

    $link = McpFixtures::tool($this, $token, 'create_upload_link')['structuredContent'];
    $path = parse_url($link['url'], PHP_URL_PATH);

    $this->post($path, ['file' => UploadedFile::fake()->createWithContent('catalogo.png', mcpPngBytes())])
        ->assertCreated()
        ->assertJsonPath('file.type', 'image')
        ->assertJsonPath('file.filename', 'catalogo.png');

    $this->post($path, ['file' => UploadedFile::fake()->createWithContent('b.png', mcpPngBytes())])
        ->assertNotFound();
});

it('re-checks the connection when a link is redeemed', function () {
    [$connection, $token] = McpFixtures::connect(McpFixtures::user());

    $path = parse_url(McpFixtures::tool($this, $token, 'create_upload_link')['structuredContent']['url'], PHP_URL_PATH);

    $connection->revoke();

    $this->post($path, ['file' => UploadedFile::fake()->createWithContent('a.png', mcpPngBytes())])
        ->assertForbidden();

    expect(Storage::disk('public')->allFiles('uploads'))->toBe([]);
});

// ─────────────────────────── Reusing the gallery ───────────────────────────

it('lists files people uploaded to the gallery by hand, with a url a flow can use', function () {
    $user = McpFixtures::user(['flows.view', 'gallery.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ, Scopes::MEDIA_READ]);

    $asset = mcpGalleryAsset($user->tenant_id);
    mcpGalleryAsset(McpFixtures::user()->tenant_id, 'Não é seu');

    $result = McpFixtures::tool($this, $token, 'list_files', ['search' => 'Catálogo']);
    $files = $result['structuredContent']['files'];

    expect($files)->toHaveCount(1)
        ->and($files[0]['id'])->toBe($asset->id)
        ->and($files[0]['type'])->toBe('document')
        ->and($files[0]['url'])->toBe($asset->publicUrl());
});

it('lets a flow message carry a gallery file\'s url', function () {
    $user = McpFixtures::user(['flows.view', 'flows.create', 'gallery.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE, Scopes::MEDIA_READ]);

    $url = McpFixtures::tool($this, $token, 'list_files')['structuredContent']['files'][0]['url']
        ?? mcpGalleryAsset($user->tenant_id)->publicUrl();

    $result = McpFixtures::tool($this, $token, 'create_flow', [
        'name' => 'Catálogo',
        'nodes' => [
            ['key' => '1', 'type' => 'start', 'data' => null],
            ['key' => '2', 'type' => 'message', 'data' => [
                'messages' => [['message_type' => 'document', 'body' => 'Nosso catálogo', 'attachment_url' => $url, 'delay' => 0]],
            ]],
        ],
        'edges' => [['source_key' => '1', 'target_key' => '2', 'condition_value' => null]],
    ]);

    expect($result['isError'])->toBeFalse();
});
