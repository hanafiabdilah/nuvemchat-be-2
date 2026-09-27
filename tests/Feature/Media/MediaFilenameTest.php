<?php

use App\Services\Media\MediaFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * The last segment of a stored path is a name a human reads: the document
 * bubble prints it, "save file" writes it to disk, and on Instagram and
 * Messenger — which take no filename field at all — it is the only name the
 * customer ever sees. So `4812_68d1a2f3b4c5d.pdf` was never an internal detail.
 *
 * What the tests below hold onto: the name survives, the uniqueness suffix
 * trails it rather than replacing it, and nothing the uploader writes in a
 * filename can change the meaning of the path it lands in.
 */
uses(RefreshDatabase::class);

// ── The name survives ──

it('keeps the original name and puts the unique code after it', function () {
    expect(MediaFilename::build('Contrato de Servico.pdf', 'pdf', '4812'))
        ->toBe('Contrato-de-Servico_4812.pdf');
});

it('keeps capitals, so a name still reads the way it was typed', function () {
    expect(MediaFilename::build('NotaFiscal.PDF', 'pdf', '7'))->toBe('NotaFiscal_7.pdf');
});

it('transliterates accents instead of dropping the letters', function () {
    // Deleting them would leave `Relatrio`, which is worse than either the
    // original or a hash — it looks like a typo rather than a transliteration.
    expect(MediaFilename::build('Relatório Anual.xlsx', 'xlsx', '9'))
        ->toBe('Relatorio-Anual_9.xlsx');
});

it('keeps the real extension when the name carries several dots', function () {
    expect(MediaFilename::build('backup.tar.gz', 'gz', '3'))->toBe('backup.tar_3.gz');
});

it('takes the extension from the caller, not from the name', function () {
    // The caller derives it from the file's content. A name claiming `.png` on
    // a PDF is exactly the mismatch UploadPolicy::storedExtension() resolves,
    // and this must not quietly re-introduce it.
    expect(MediaFilename::build('invoice.png', 'pdf', '1'))->toBe('invoice_1.pdf');
});

// ── Uniqueness ──

it('generates a unique code when there is no natural key', function () {
    $first = MediaFilename::build('catalogo.pdf', 'pdf');
    $second = MediaFilename::build('catalogo.pdf', 'pdf');

    // `uploads/` is flat and shared across every tenant, so two businesses
    // sending `catalogo.pdf` must not collide — the second write would replace
    // the first, and the first one's flow node would carry on sending somebody
    // else's file.
    expect($first)->not->toBe($second)
        ->and($first)->toStartWith('catalogo_')
        ->and($first)->toEndWith('.pdf');
});

it('is stable for the same natural key, so a retried download overwrites itself', function () {
    // A message has one attachment, and DownloadInboundMedia retries up to
    // three times. Re-using the id means attempt two replaces attempt one
    // instead of orphaning it on disk.
    expect(MediaFilename::build('boleto.pdf', 'pdf', '512'))
        ->toBe(MediaFilename::build('boleto.pdf', 'pdf', '512'));
});

// ── Nothing in a name may change the meaning of a path ──

it('strips a directory out of the name', function () {
    expect(MediaFilename::build('../../etc/passwd', 'txt', '1'))->toBe('passwd_1.txt');
    expect(MediaFilename::build('..\\..\\windows\\system.ini', 'txt', '1'))->toBe('system_1.txt');
});

it('never produces a segment made only of dots', function () {
    foreach (['..', '.', '....', '../'] as $name) {
        expect(MediaFilename::build($name, 'pdf', '1'))->toBe('arquivo_1.pdf');
    }
});

it('produces only characters that are safe in a path and a URL', function () {
    $built = MediaFilename::build("a'b\"c<d>e?f#g&h%i j/k\\l\0m.pdf", 'pdf', '1');

    expect($built)->toMatch('/^[A-Za-z0-9._-]+$/');
});

it('is ASCII even when the name is not', function () {
    // Non-ASCII would survive a URL, but these keys are moving onto object
    // storage, where a key that upsets presigning is a silent media outage.
    $built = MediaFilename::build('写真とファイル.jpg', 'jpg', '1');

    expect(mb_check_encoding($built, 'ASCII'))->toBeTrue()
        ->and($built)->toEndWith('_1.jpg');
});

it('bounds the length', function () {
    $built = MediaFilename::build(str_repeat('a', 500).'.pdf', 'pdf', '1');

    expect(strlen($built))->toBeLessThanOrEqual(100)
        ->and($built)->toEndWith('_1.pdf');
});

// ── Fallbacks ──

it('falls back when there is no usable name', function () {
    expect(MediaFilename::build(null, 'jpg', '1'))->toBe('arquivo_1.jpg');
    expect(MediaFilename::build('', 'jpg', '1'))->toBe('arquivo_1.jpg');
    // Nothing but an extension: a dotfile has no base name at all.
    expect(MediaFilename::build('.htaccess', 'txt', '1'))->toBe('arquivo_1.txt');
});

it('uses the caller fallback, so a channel that reports no name still says what the file is', function () {
    // Meta reports no filename for Messenger or Instagram attachments, so the
    // message type is the most the name can honestly say.
    expect(MediaFilename::build(null, 'jpg', '4812', 'image'))->toBe('image_4812.jpg');
});

it('copes with a file that has no extension at all', function () {
    expect(MediaFilename::build('README', '', '1'))->toBe('README_1');
});

// ── POST /api/uploads: flow nodes, carousel cards, campaign media ──

it('stores a flow attachment under its own name instead of a hash', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('Foto do Produto.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk();

    // This URL is written into a flow node and sent for months. WhatsApp names
    // a document after the last segment of the URL it fetched, and Instagram
    // and Messenger offer no other name — so this string is the customer's name
    // for the file.
    expect(basename($response->json('path')))->toStartWith('Foto-do-Produto_')
        ->and($response->json('path'))->toEndWith('.png')
        ->and($response->json('path'))->toStartWith('uploads/')
        // The pristine name still comes back for the dashboard to show.
        ->and($response->json('filename'))->toBe('Foto do Produto.png');
});

it('does not let an upload name escape the uploads directory', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('../../../evil.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('path'))->toStartWith('uploads/')
        ->and($response->json('path'))->not->toContain('..');
});

it('keeps two uploads of the same name apart', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $paths = collect(range(1, 2))->map(fn () => $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('catalogo.png', 40, 40),
    ], ['Accept' => 'application/json'])->assertOk()->json('path'));

    expect($paths->unique())->toHaveCount(2);
});
