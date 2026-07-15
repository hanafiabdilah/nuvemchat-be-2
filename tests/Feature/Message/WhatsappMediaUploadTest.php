<?php

use App\Models\Connection;
use App\Services\Message\Handlers\WhatsappOfficialHandler;
use Illuminate\Support\Facades\Http;

function callUploadMedia(Connection $connection, string $content, string $mime, string $filename): string
{
    $handler = new WhatsappOfficialHandler();
    $ref = new ReflectionMethod($handler, 'uploadMedia');
    $ref->setAccessible(true);

    return $ref->invoke($handler, $connection, $content, $mime, $filename);
}

function fakeConnection(): Connection
{
    return new Connection([
        'credentials' => [
            'access_token' => 'TEST_TOKEN',
            'phone_number_id' => 'PN123',
        ],
    ]);
}

test('uploads bytes to the media endpoint and returns the media id', function () {
    Http::fake([
        '*/PN123/media' => Http::response(['id' => 'MEDIA_ID_1'], 200),
    ]);

    $id = callUploadMedia(fakeConnection(), 'rawbytes', 'image/png', 'pic.png');

    expect($id)->toBe('MEDIA_ID_1');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/PN123/media')
            && $request->hasHeader('Authorization', 'Bearer TEST_TOKEN')
            && $request->isMultipart();
    });
});

test('throws when the media upload response has no id', function () {
    Http::fake([
        '*/PN123/media' => Http::response(['error' => ['message' => 'bad media']], 400),
    ]);

    expect(fn () => callUploadMedia(fakeConnection(), 'x', 'image/png', 'p.png'))
        ->toThrow(Exception::class, 'bad media');
});
