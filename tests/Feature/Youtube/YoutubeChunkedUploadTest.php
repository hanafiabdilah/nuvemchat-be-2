<?php

use App\Services\Youtube\YoutubeChunkedUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    File::deleteDirectory(storage_path('app/upload-chunks'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/upload-chunks'));
});

/**
 * A file that reads as MP4 to finfo (an `ftyp` box) and is just over one piece
 * long, cut the way the browser cuts it.
 *
 * @return array{0: list<UploadedFile>, 1: int}
 */
function videoPieces(bool $video = true): array
{
    $size = YoutubeChunkedUpload::CHUNK_BYTES + 1000;
    $head = $video ? "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" : '<html><body>not a video</body></html>';
    $bytes = $head.str_repeat("\x00", $size - strlen($head));

    $pieces = [];
    foreach (str_split($bytes, YoutubeChunkedUpload::CHUNK_BYTES) as $i => $piece) {
        $path = tempnam(sys_get_temp_dir(), 'yt-piece-');
        file_put_contents($path, $piece);
        $pieces[] = new UploadedFile($path, "piece-{$i}", 'application/octet-stream', null, true);
    }

    return [$pieces, $size];
}

function sendPiece($test, $user, $account, UploadedFile $piece, int $index, int $size, string $id = 'abcdef0123456789abcd')
{
    return $test->actingAs($user)->post("/api/youtube/accounts/{$account->id}/uploads/chunks", [
        'upload_id' => $id,
        'index' => $index,
        'total' => 2,
        'size' => $size,
        'name' => 'Lançamento.mp4',
        'chunk' => $piece,
    ], ['Accept' => 'application/json']);
}

test('a video sent in pieces is assembled into one stored file', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    [$pieces, $size] = videoPieces();

    sendPiece($this, $user, $account, $pieces[0], 0, $size)->assertOk()->assertJsonPath('data.next_index', 1);
    $done = sendPiece($this, $user, $account, $pieces[1], 1, $size)->assertCreated();

    $path = $done->json('data.path');

    expect($path)->toStartWith("youtube/{$user->tenant_id}/")
        ->and($done->json('data.size'))->toBe($size)
        ->and($done->json('data.mime'))->toBe('video/mp4');
    Storage::disk('local')->assertExists($path);
});

test('a piece sent twice is not written twice, and a skipped one is asked for again', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    [$pieces, $size] = videoPieces();

    // Out of order: the server says which piece it is waiting for.
    sendPiece($this, $user, $account, $pieces[1], 1, $size)->assertOk()->assertJsonPath('data.next_index', 0);

    sendPiece($this, $user, $account, $pieces[0], 0, $size)->assertOk();
    // The same piece again — a retry whose answer was lost.
    sendPiece($this, $user, $account, $pieces[0], 0, $size)->assertOk()->assertJsonPath('data.next_index', 1);

    $done = sendPiece($this, $user, $account, $pieces[1], 1, $size)->assertCreated();
    expect($done->json('data.size'))->toBe($size);

    // And the last piece retried after it landed answers with the same file.
    expect(sendPiece($this, $user, $account, $pieces[1], 1, $size)->json('data.path'))->toBe($done->json('data.path'));
});

test('something that only claims to be a video is refused once assembled', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    [$pieces, $size] = videoPieces(video: false);

    sendPiece($this, $user, $account, $pieces[0], 0, $size, 'zzzzzz0123456789zzzz')->assertOk();
    sendPiece($this, $user, $account, $pieces[1], 1, $size, 'zzzzzz0123456789zzzz')->assertUnprocessable();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('pieces that do not add up to the declared size are refused before any is kept', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    [$pieces, $size] = videoPieces();

    $this->actingAs($user)->post("/api/youtube/accounts/{$account->id}/uploads/chunks", [
        'upload_id' => 'abcdef0123456789abcd',
        'index' => 0,
        'total' => 5,
        'size' => $size,
        'name' => 'video.mp4',
        'chunk' => $pieces[0],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('index');
});
