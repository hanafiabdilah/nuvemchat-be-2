<?php

use App\Services\Media\MediaStorage;
use App\Services\Message\OutboundMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Stored names keep the customer's spelling, and a URL cannot carry it.
 *
 * That is the whole trade: the name is pristine on disk and in the dashboard,
 * and the encoding happens at the one boundary that needs it. These tests hold
 * both directions, because either one alone is a broken link — an encoded name
 * that nobody decodes reaches WhatsApp as `Contrato%20de%20Servi%C3%A7o.pdf`,
 * and a raw name that nobody encodes is not a URL at all.
 */
const PRISTINE = 'Contrato de Serviço (2).pdf';

it('encodes a stored path per segment, keeping the slashes', function () {
    expect(MediaStorage::encodePath('media/9/'.PRISTINE))
        ->toBe('media/9/Contrato%20de%20Servi%C3%A7o%20%282%29.pdf');
});

it('writes a space as %20 and never as +', function () {
    // `+` is form syntax; inside a path it means a literal plus, and a reader
    // using urldecode() instead of rawurldecode() would hand back the wrong name.
    expect(MediaStorage::encodePath('uploads/3/my file.pdf'))->toContain('my%20file.pdf')
        ->and(MediaStorage::encodePath('uploads/3/my file.pdf'))->not->toContain('+');
});

it('serves a pristine name back through its signed URL', function () {
    Storage::fake('local');
    $path = 'media/9/'.PRISTINE;
    Storage::disk('local')->put($path, 'bytes');

    // The signature covers the encoded URL, so this asserts the encoding is
    // consistent between the generator and the route — the failure mode that
    // would turn every attachment into a 403.
    $response = $this->get(MediaStorage::signedUrl($path, now()->addDay()))->assertOk();

    // streamedContent(), because the route streams the file: getContent() on a
    // StreamedResponse is empty until it is sent.
    expect($response->streamedContent())->toBe('bytes');
});

it('encodes the published URL a channel will fetch', function () {
    $url = MediaStorage::publishedUrl('uploads/3/'.PRISTINE);

    expect($url)->toContain('Contrato%20de%20Servi%C3%A7o%20%282%29.pdf')
        // A literal space would end the URL for some fetchers.
        ->and($url)->not->toContain(' ');
});

it('announces the decoded name to the channel', function () {
    // Instagram and Messenger take no filename field, so this basename is the
    // only name their customer sees.
    $media = OutboundMedia::fromData([
        'media_url' => 'https://cdn.example.com/'.MediaStorage::encodePath('uploads/3/'.PRISTINE),
    ], 'document');

    expect($media->filename)->toBe(PRISTINE)
        ->and($media->extension)->toBe('pdf');
});

it('still resolves a widget attachment whose URL was encoded', function () {
    Storage::fake('local');

    $path = 'widget-uploads/session-token/a1b2c3d4e5f6/'.PRISTINE;
    Storage::disk('local')->put($path, 'bytes');

    // What the widget posts back is the URL we gave it, encoded. The ownership
    // check matches on the session token and then asks the disk — which knows
    // the file by its real name, not its URL spelling.
    $resolved = (fn () => $this->resolveAttachmentPath(
        MediaStorage::signedUrl($path, now()->addHour()),
        'session-token',
    ))->call(app(App\Http\Controllers\Widget\WidgetController::class));

    expect($resolved)->toBe($path);
});
