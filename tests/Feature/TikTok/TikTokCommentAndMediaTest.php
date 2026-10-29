<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\Media\ChunkedUpload;
use App\Services\TikTok\TikTokUrlProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TikTokFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    File::deleteDirectory(storage_path('app/upload-chunks'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/upload-chunks'));
});

function tiktokComment(string $id, bool $owner = false, array $replies = [], string $status = 'PUBLIC'): array
{
    return [
        'comment_id' => $id,
        'video_id' => 'v1',
        'text' => "Comentário {$id}",
        'username' => $owner ? 'lojaaurora' : 'cliente',
        'display_name' => $owner ? 'Loja Aurora' : 'Cliente',
        'likes' => 3,
        'replies' => count($replies),
        'owner' => $owner,
        'liked' => false,
        'pinned' => false,
        'status' => $status,
        'create_time' => '1761700000',
        'reply_list' => $replies,
    ];
}

test('a video\'s comments come back with what the account wrote and what is hidden', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    TikTokFixtures::fake([
        '/business/comment/list/' => TikTokFixtures::ok([
            'comments' => [
                tiktokComment('c1', replies: [tiktokComment('r1', owner: true) + ['parent_comment_id' => 'c1']]),
                tiktokComment('c2', status: 'HIDDEN'),
            ],
            'cursor' => 30,
            'has_more' => true,
        ]),
    ]);

    $response = $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/videos/v1/comments")->assertOk();

    expect($response->json('data.0.replied'))->toBeTrue()
        ->and($response->json('data.0.replies.0.from_account'))->toBeTrue()
        ->and($response->json('data.1.hidden'))->toBeTrue()
        ->and($response->json('next_cursor'))->toBe('30');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'video_id=v1')
        && str_contains($request->url(), 'status=ALL')
        && str_contains($request->url(), 'include_replies=true'));
});

test('replying, liking, hiding and deleting go to the matching TikTok endpoints', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    TikTokFixtures::fake([
        '/business/comment/reply/create/' => fn ($request) => TikTokFixtures::ok([
            'comment_id' => 'new', 'parent_comment_id' => 'c1', 'text' => $request['text'],
        ]),
        '/business/comment/' => TikTokFixtures::ok(),
    ]);

    $this->actingAs($user)->postJson("/api/tiktok/accounts/{$connection->id}/videos/v1/comments/c1/replies", ['text' => 'Obrigado!'])
        ->assertCreated()
        ->assertJsonPath('data.from_account', true)
        ->assertJsonPath('data.text', 'Obrigado!');

    $this->actingAs($user)->patchJson("/api/tiktok/accounts/{$connection->id}/videos/v1/comments/c1", ['liked' => true, 'hidden' => true])->assertOk();
    $this->actingAs($user)->deleteJson("/api/tiktok/accounts/{$connection->id}/comments/c9")->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/comment/like/') && $request['action'] === 'LIKE');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/comment/hide/') && $request['action'] === 'HIDE' && $request['video_id'] === 'v1');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/comment/delete/') && $request['comment_id'] === 'c9');
});

test('moderation needs the moderation permission, and the user\'s own access to the account', function () {
    $viewer = TikTokFixtures::user(['tiktok-posts.view']);
    $connection = TikTokFixtures::connection($viewer);
    Http::fake();

    $this->actingAs($viewer)->deleteJson("/api/tiktok/accounts/{$connection->id}/comments/c1")->assertForbidden();

    $moderator = TikTokFixtures::user();
    $theirs = TikTokFixtures::connection($moderator);
    $moderator->connections()->detach($theirs->id);

    $this->actingAs($moderator)->getJson("/api/tiktok/accounts/{$theirs->id}/videos/v1/comments")->assertForbidden();
    Http::assertNothingSent();
});

test('a video sent in pieces lands on the published disk, where TikTok can fetch it', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    $size = ChunkedUpload::CHUNK_BYTES + 500;
    $head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
    $bytes = $head.str_repeat("\x00", $size - strlen($head));
    $send = function (int $index) use ($user, $connection, $bytes, $size) {
        $path = tempnam(sys_get_temp_dir(), 'tt-piece-');
        file_put_contents($path, substr($bytes, $index * ChunkedUpload::CHUNK_BYTES, ChunkedUpload::CHUNK_BYTES));

        return $this->actingAs($user)->post("/api/tiktok/accounts/{$connection->id}/uploads/chunks", [
            'upload_id' => 'abcdef0123456789abcd',
            'index' => $index,
            'total' => 2,
            'size' => $size,
            'name' => 'Lançamento.mp4',
            'chunk' => new UploadedFile($path, 'piece', 'application/octet-stream', null, true),
        ], ['Accept' => 'application/json']);
    };

    $send(0)->assertOk()->assertJsonPath('data.next_index', 1);
    $done = $send(1)->assertCreated();

    expect($done->json('data.path'))->toStartWith("tiktok/{$user->tenant_id}/")
        ->and($done->json('data.url'))->toStartWith(TikTokUrlProperty::currentPrefix())
        ->and($done->json('data.media_type'))->toBe('video');
    Storage::disk('public')->assertExists($done->json('data.path'));
});

test('verifying the media address serves TikTok\'s signature file and records the result', function () {
    Setting::set(TikTokConfig::KEY_APP_ID, 'app-1');
    Setting::set(TikTokConfig::KEY_APP_SECRET, 'secret-1');

    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    TikTokFixtures::fake([
        '/business/property/list/' => TikTokFixtures::ok(['url_property_info_list' => []]),
        '/business/property/add/' => TikTokFixtures::ok(['url_property_info' => [
            'property_type' => 2, 'url' => TikTokUrlProperty::currentPrefix(),
            'property_status' => 0, 'signature' => 'sig-abc', 'file_name' => 'tiktokXYZ.txt',
        ]]),
        '/business/property/verify/' => TikTokFixtures::ok(['url_property_info' => ['property_status' => 1]]),
    ]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/admin/tiktok/url-property/verify')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.matches', true);

    expect(Storage::disk('public')->get('tiktok/tiktokXYZ.txt'))->toBe('sig-abc')
        ->and(TikTokConfig::verifiedPrefix())->toBe(TikTokUrlProperty::currentPrefix());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/property/add/')
        && $request['app_id'] === 'app-1'
        && $request['url_property_meta']['property_type'] === 2);
});
