<?php

use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Flow, campaign and product media, renamed without rewriting history.
 *
 * The whole design turns on one number: production has 677 `messages.attachment`
 * rows pointing into `uploads/`. Those record the file a customer was actually
 * sent, so the original must keep serving — which is why this copies and leaves
 * the old file alone, and why a reference it fails to find keeps working too.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('public'));

function uploadFlowNode(string $url, ?Tenant $tenant = null): FlowNode
{
    $user = User::factory()->create();
    $tenant ??= Tenant::create(['user_id' => $user->id]);
    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'FUNIL #1']);

    return FlowNode::create([
        'flow_id' => $flow->id,
        'type' => NodeType::Message,
        'position_x' => 0,
        'position_y' => 0,
        'data' => ['messages' => [['message_type' => 'document', 'body' => '', 'attachment_url' => $url]]],
    ]);
}

it('strips the code and repoints the flow node', function () {
    Storage::disk('public')->put('uploads/ALFABETIZACAO-A4_907616737a81.pdf', 'pdf-bytes');
    $node = uploadFlowNode(MediaStorage::publishedUrl('uploads/ALFABETIZACAO-A4_907616737a81.pdf'));
    $tenant = $node->flow->tenant_id;

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    expect($node->fresh()->data['messages'][0]['attachment_url'])
        ->toBe(MediaStorage::publishedUrl("uploads/{$tenant}/ALFABETIZACAO-A4.pdf"))
        ->and(Storage::disk('public')->get("uploads/{$tenant}/ALFABETIZACAO-A4.pdf"))->toBe('pdf-bytes');
});

it('leaves the original file exactly where it is', function () {
    Storage::disk('public')->put('uploads/PONTUACAO-A4_f43901476ed4.pdf', 'pdf-bytes');
    uploadFlowNode(MediaStorage::publishedUrl('uploads/PONTUACAO-A4_f43901476ed4.pdf'));

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    // 677 message rows in production point at addresses like this one. Moving
    // the file would rewrite the past into a 404.
    expect(Storage::disk('public')->exists('uploads/PONTUACAO-A4_f43901476ed4.pdf'))->toBeTrue();
});

it('never writes to the tables that record what was sent', function () {
    // ⚠️ The guarantee this command rests on. Production has 677
    // `messages.attachment` rows, 15 `messages.meta`, 8 `messages.body` and an
    // audit-log entry pointing into `uploads/` — a record of the file a
    // customer was actually sent. Rewriting any of them would claim we sent
    // something we did not, and it is the kind of thing a later "let's be
    // thorough" change adds without noticing. Asserted on the source, because
    // the damage would be invisible in any single test.
    $source = file_get_contents(base_path('app/Console/Commands/Media/RestoreUploadFilenames.php'));

    foreach (['messages', 'audit_logs', 'Message::', 'AuditLog'] as $forbidden) {
        expect(str_contains(preg_replace('~/\*.*?\*/~s', '', $source) ?? '', $forbidden))
            ->toBeFalse("the command must not touch {$forbidden}");
    }
});

it('leaves a hashName upload alone and says why', function () {
    // Laravel's hashName(): POST /api/uploads wrote no database row, so the
    // original name was never stored anywhere and cannot be recovered.
    $path = 'uploads/oLXug5pUMJYR4PWqPGCD8brpNSWRfprrtPd1NpKl.mp3';
    Storage::disk('public')->put($path, 'mp3');
    $node = uploadFlowNode(MediaStorage::publishedUrl($path));

    $this->artisan('media:restore-upload-filenames')
        ->expectsOutputToContain('had no name to recover')
        ->assertSuccessful();

    expect($node->fresh()->data['messages'][0]['attachment_url'])
        ->toBe(MediaStorage::publishedUrl($path));
});

it('leaves a file already in a workspace folder alone', function () {
    $path = 'uploads/7/Contrato de Serviço.pdf';
    Storage::disk('public')->put($path, 'pdf');
    $node = uploadFlowNode(MediaStorage::publishedUrl($path));

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    expect($node->fresh()->data['messages'][0]['attachment_url'])->toBe(MediaStorage::publishedUrl($path));
});

it('keeps both when the workspace folder already has that name', function () {
    Storage::disk('public')->put('uploads/CLASSES-GRAMATICAIS-A4_8adb9a72aebb.pdf', 'new-bytes');
    $node = uploadFlowNode(MediaStorage::publishedUrl('uploads/CLASSES-GRAMATICAIS-A4_8adb9a72aebb.pdf'));
    $tenant = $node->flow->tenant_id;
    Storage::disk('public')->put("uploads/{$tenant}/CLASSES-GRAMATICAIS-A4.pdf", 'someone else');

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    // "Probably the same file" is not good enough to point a live flow at
    // somebody else's bytes.
    expect($node->fresh()->data['messages'][0]['attachment_url'])
        ->toBe(MediaStorage::publishedUrl("uploads/{$tenant}/CLASSES-GRAMATICAIS-A4 (2).pdf"))
        ->and(Storage::disk('public')->get("uploads/{$tenant}/CLASSES-GRAMATICAIS-A4.pdf"))->toBe('someone else');
});

it('gives each workspace its own copy', function () {
    Storage::disk('public')->put('uploads/GENEROS-TEXTUAIS-A4_bab1b4500087.pdf', 'pdf');
    $url = MediaStorage::publishedUrl('uploads/GENEROS-TEXTUAIS-A4_bab1b4500087.pdf');
    $a = uploadFlowNode($url);
    $b = uploadFlowNode($url);

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    expect($a->fresh()->data['messages'][0]['attachment_url'])
        ->toBe(MediaStorage::publishedUrl("uploads/{$a->flow->tenant_id}/GENEROS-TEXTUAIS-A4.pdf"))
        ->and($b->fresh()->data['messages'][0]['attachment_url'])
        ->toBe(MediaStorage::publishedUrl("uploads/{$b->flow->tenant_id}/GENEROS-TEXTUAIS-A4.pdf"));
});

it('changes nothing on a dry run', function () {
    $path = 'uploads/AUDIO-01-FUNIL-NOVO_152fe294e1df.mp3';
    Storage::disk('public')->put($path, 'mp3');
    $node = uploadFlowNode(MediaStorage::publishedUrl($path));

    $this->artisan('media:restore-upload-filenames', ['--dry-run' => true])->assertSuccessful();

    expect($node->fresh()->data['messages'][0]['attachment_url'])->toBe(MediaStorage::publishedUrl($path))
        ->and(Storage::disk('public')->allFiles("uploads/{$node->flow->tenant_id}"))->toBe([]);
});

it('is idempotent', function () {
    Storage::disk('public')->put('uploads/PRODUCAO-TEXTUAL-A4_8dc20b8b2283.pdf', 'pdf');
    $node = uploadFlowNode(MediaStorage::publishedUrl('uploads/PRODUCAO-TEXTUAL-A4_8dc20b8b2283.pdf'));

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();
    $settled = $node->fresh()->data['messages'][0]['attachment_url'];

    $this->artisan('media:restore-upload-filenames')->assertSuccessful();

    expect($node->fresh()->data['messages'][0]['attachment_url'])->toBe($settled)
        ->and(Storage::disk('public')->allFiles("uploads/{$node->flow->tenant_id}"))->toHaveCount(1);
});
