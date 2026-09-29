<?php

use App\Services\Media\MediaFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * The last segment of a stored path is a name a human reads: the document
 * bubble prints it, "save file" writes it to disk, and on Instagram and
 * Messenger — which take no filename field at all — it is the only name the
 * customer ever sees.
 *
 * So the name is kept whole, with nothing appended, and **uniqueness lives in
 * the directory instead**. That trade is the whole point of this class, and
 * these tests hold both halves of it: the name stays clean, and two files can
 * never land on one path.
 */
uses(RefreshDatabase::class);

// ⚠️ The published disk is faked per test, not shared. Names are no longer
// unique by construction, so a file one test writes to `uploads/1` is a file
// the next test collides with — which is the new behaviour working, in the
// wrong place.
beforeEach(fn () => Storage::fake('public'));

// ── The name is the original, and nothing else ──

it('keeps the original name, spelling and all, with no code appended', function () {
    // Spaces and accents survive: a URL cannot carry them, and that is the URL's
    // problem to solve — see MediaUrlEncodingTest.
    expect(MediaFilename::path('media', '4812', 'Contrato de Serviço.pdf', 'pdf'))
        ->toBe('media/4812/Contrato de Serviço.pdf');
});

it('keeps capitals, so a name still reads the way it was typed', function () {
    expect(MediaFilename::name('NotaFiscal.PDF', 'pdf'))->toBe('NotaFiscal.pdf');
});

it('keeps accents and parentheses', function () {
    expect(MediaFilename::name('Relatório Anual (2026).xlsx', 'xlsx'))
        ->toBe('Relatório Anual (2026).xlsx');
});

it('removes only what a path or a URL cannot carry', function () {
    // The Windows-illegal set, plus the three that are structural in a URL.
    expect(MediaFilename::name('a:b*c"d<e>f|g?h#i%j.pdf', 'pdf'))->toBe('abcdefghij.pdf');
});

it('collapses whitespace and refuses to end on a dot or a space', function () {
    expect(MediaFilename::name("  spaced\t  out  .pdf", 'pdf'))->toBe('spaced out.pdf');
});

it('keeps the real extension when the name carries several dots', function () {
    expect(MediaFilename::name('backup.tar.gz', 'gz'))->toBe('backup.tar.gz');
});

it('takes the extension from the caller, not from the name', function () {
    // The caller derives it from the file's content. A name claiming `.png` on
    // a PDF is exactly the mismatch UploadPolicy::storedExtension() resolves,
    // and this must not quietly re-introduce it.
    expect(MediaFilename::name('invoice.png', 'pdf'))->toBe('invoice.pdf');
});

// ── Uniqueness, which now lives one level up ──

it('puts two files with the same name on different paths', function () {
    $first = MediaFilename::path('uploads', MediaFilename::token(), 'catalogo.pdf', 'pdf');
    $second = MediaFilename::path('uploads', MediaFilename::token(), 'catalogo.pdf', 'pdf');

    // `uploads/` is flat and shared across every tenant, so two businesses
    // sending `catalogo.pdf` must not collide — the second write would replace
    // the first, and the first one's flow node would carry on sending somebody
    // else's file.
    expect($first)->not->toBe($second)
        // …and each of them is still named exactly what the customer called it.
        ->and(basename($first))->toBe('catalogo.pdf')
        ->and(basename($second))->toBe('catalogo.pdf');
});

it('is stable for the same natural key, so a retried download overwrites itself', function () {
    // A message has one attachment, and DownloadInboundMedia retries up to
    // three times. Re-using the id means attempt two replaces attempt one
    // instead of orphaning it on disk.
    expect(MediaFilename::path('media', '512', 'boleto.pdf', 'pdf'))
        ->toBe(MediaFilename::path('media', '512', 'boleto.pdf', 'pdf'))
        ->toBe('media/512/boleto.pdf');
});

it('separates the parts of one mail, which may share a filename', function () {
    // Outlook names every inline image `image001.png`, so the part index has to
    // be part of the path — and it is a directory, not a suffix on the name.
    $first = MediaFilename::path('media', '900/0', 'image001.png', 'png');
    $second = MediaFilename::path('media', '900/1', 'image001.png', 'png');

    expect($first)->toBe('media/900/0/image001.png')
        ->and($second)->toBe('media/900/1/image001.png');
});

it('generates a token wide enough for a folder every tenant shares', function () {
    expect(MediaFilename::token())->toMatch('/^[0-9a-f]{12}$/')
        ->and(MediaFilename::token())->not->toBe(MediaFilename::token());
});

// ── Nothing in a name or a key may change the meaning of a path ──

it('strips a directory out of the name', function () {
    expect(MediaFilename::path('media', '1', '../../etc/passwd', 'txt'))->toBe('media/1/passwd.txt');
    // `.ini` is gone because the extension always comes from the content, not
    // from the name — see UploadPolicy::storedExtension().
    expect(MediaFilename::path('media', '1', '..\\..\\windows\\system.ini', 'txt'))->toBe('media/1/system.txt');
});

it('strips traversal out of the directory key too', function () {
    // Callers build these from ids, but they are still interpolated into a
    // storage path.
    expect(MediaFilename::path('media', '../../evil', 'x.pdf', 'pdf'))->toBe('media/evil/x.pdf');
});

it('never produces a segment made only of dots', function () {
    foreach (['..', '.', '....', '../'] as $name) {
        expect(MediaFilename::path('media', '1', $name, 'pdf'))->toBe('media/1/arquivo.pdf');
    }
});

it('produces only characters that are safe in a path and a URL', function () {
    $built = MediaFilename::name("a'b\"c<d>e?f#g&h%i j/k\\l\0m.pdf", 'pdf');

    expect($built)->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('keeps a name in a script that has no ASCII form', function () {
    // Transliteration used to turn this into `arquivo.jpg`, which tells the
    // person nothing at all. It is valid UTF-8, it is valid in a path, and the
    // URL layer encodes it.
    expect(MediaFilename::name('写真とファイル.jpg', 'jpg'))->toBe('写真とファイル.jpg');
});

it('repairs a name that is not valid UTF-8', function () {
    // An old mail client sending Latin-1. Left alone it would make every /u
    // pattern bail, and the JSON cast on `messages.meta` would refuse it later.
    $built = MediaFilename::name("Rela\xE7\xE3o.pdf", 'pdf');

    expect(mb_check_encoding($built, 'UTF-8'))->toBeTrue()
        ->and($built)->toEndWith('.pdf');
});

it('bounds the length in bytes, without splitting a letter', function () {
    // Bytes, not characters: an accented letter is two, and both the 255-byte
    // filesystem segment and the 1024-byte object-storage key count bytes.
    $built = MediaFilename::name(str_repeat('é', 400).'.pdf', 'pdf');

    expect(strlen($built))->toBeLessThanOrEqual(160)
        // Cutting mid-letter would leave a string the JSON cast rejects.
        ->and(mb_check_encoding($built, 'UTF-8'))->toBeTrue()
        ->and($built)->toEndWith('.pdf');
});

// ── Fallbacks ──

it('falls back when there is no usable name', function () {
    expect(MediaFilename::name(null, 'jpg'))->toBe('arquivo.jpg');
    expect(MediaFilename::name('', 'jpg'))->toBe('arquivo.jpg');
    // Nothing but an extension: a dotfile has no base name at all.
    expect(MediaFilename::name('.htaccess', 'txt'))->toBe('arquivo.txt');
});

it('uses the caller fallback, so a channel that reports no name still says what the file is', function () {
    // Meta reports no filename for Messenger or Instagram attachments, so the
    // message type is the most the name can honestly say.
    expect(MediaFilename::path('media', '4812', null, 'jpg', 'image'))->toBe('media/4812/image.jpg');
});

it('copes with a file that has no extension at all', function () {
    expect(MediaFilename::name('README', ''))->toBe('README');
});

// ── POST /api/uploads: one folder per workspace ──

it('stores a flow attachment in the workspace folder, under its own name', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('Foto do Produto.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk();

    // This URL is written into a flow node and sent for months. WhatsApp names
    // a document after the last segment of the URL it fetched, and Instagram
    // and Messenger offer no other name — so this string is the customer's name
    // for the file, and it is now exactly the name they gave it.
    expect($response->json('path'))->toBe("uploads/{$tenant->id}/Foto do Produto.png")
        ->and($response->json('filename'))->toBe('Foto do Produto.png')
        ->and($response->json('renamed'))->toBeFalse()
        ->and($response->json('replaced'))->toBeFalse();
});

it('does not let an upload name escape the workspace folder', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('../../../evil.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('path'))->toBe("uploads/{$tenant->id}/evil.png");
});

it('keeps one workspace out of another one\'s folder', function () {
    $a = GalleryFixtures::tenant(planGb: 1);
    $b = GalleryFixtures::tenant(planGb: 1);

    Sanctum::actingAs($a->user);
    $first = $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 40, 40)],
        ['Accept' => 'application/json'])->assertOk()->json('path');

    Sanctum::actingAs($b->user);
    $second = $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 40, 40)],
        ['Accept' => 'application/json'])->assertOk()->json('path');

    // The whole reason the bare name is usable as an address: two businesses
    // sending `catalogo.pdf` are never asked about each other.
    expect($first)->not->toBe($second)
        ->and(basename($first))->toBe('catalogo.png')
        ->and(basename($second))->toBe('catalogo.png');
});

// ── The same name twice, inside one workspace ──

it('asks instead of guessing when the workspace already has the name', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 40, 40)],
        ['Accept' => 'application/json'])->assertOk();

    // 409, not 422: nothing about the request is wrong. And the refusal carries
    // what the dialog needs — a person choosing between "replace" and "keep
    // both" is really asking which file is already there.
    $conflict = $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 40, 40)],
        ['Accept' => 'application/json'])->assertStatus(409);

    expect($conflict->json('code'))->toBe('file_exists')
        ->and($conflict->json('filename'))->toBe('catalogo.png')
        ->and($conflict->json('existing.size'))->toBeGreaterThan(0)
        ->and($conflict->json('existing.modified_at'))->not->toBeNull()
        ->and($conflict->json('options'))->toBe(['cancel', 'replace', 'rename']);
});

it('replaces at the same URL when asked to', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $first = $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 10, 10)],
        ['Accept' => 'application/json'])->assertOk();

    $again = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('catalogo.png', 40, 40),
        'on_conflict' => 'replace',
    ], ['Accept' => 'application/json'])->assertOk();

    // The same URL on purpose: that is what makes "I fixed the catalogue" work
    // without editing every flow node. It is also why nobody may choose it for
    // the person.
    expect($again->json('url'))->toBe($first->json('url'))
        ->and($again->json('replaced'))->toBeTrue()
        ->and(Storage::disk('public')->allFiles("uploads/{$tenant->id}"))->toHaveCount(1);
});

it('keeps both by numbering the new one', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('catalogo.png', 40, 40)],
        ['Accept' => 'application/json'])->assertOk();

    $second = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('catalogo.png', 40, 40),
        'on_conflict' => 'rename',
    ], ['Accept' => 'application/json'])->assertOk();

    $third = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('catalogo.png', 40, 40),
        'on_conflict' => 'rename',
    ], ['Accept' => 'application/json'])->assertOk();

    // The numbering every file manager uses — readable now that the name keeps
    // its spaces.
    expect($second->json('filename'))->toBe('catalogo (2).png')
        ->and($third->json('filename'))->toBe('catalogo (3).png')
        ->and($second->json('renamed'))->toBeTrue();
});

it('refuses a conflict strategy it does not know', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('catalogo.png', 40, 40),
        'on_conflict' => 'overwrite-everything',
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('on_conflict');
});
