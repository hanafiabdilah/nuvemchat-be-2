<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Giving stored files their names back.
 *
 * Three shapes are on disk from three eras, and the command has to tell them
 * apart without guessing: an all-code name it can only recover from
 * `meta.filename`, a name-plus-code it can strip, and a real name that merely
 * happens to end in digits, which it must leave alone.
 *
 * That last one is why the pattern is narrow. `Relatorio_2026.pdf` is somebody's
 * file, and a command that eats the year off it is worse than one that does
 * nothing.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function restoreConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['access_token' => 't', 'phone_number_id' => 'PN1'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Maria',
        'external_id' => '5511999998888',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999998888',
        'status' => ConversationStatus::Active,
    ]);
}

/** A stored attachment, with bytes on the fake disk. */
function restoreAttachment(string $path, ?string $pristine = null, MessageType $type = MessageType::Document): Message
{
    Storage::disk('local')->put($path, 'bytes');

    $message = restoreConversation()->messages()->create([
        'external_id' => 'wamid.'.str()->random(8),
        'sender_type' => SenderType::Incoming,
        'message_type' => $type,
        'sent_at' => now(),
        'meta' => $pristine === null ? [] : ['filename' => $pristine],
    ]);

    $message->update(['attachment' => $path]);

    return $message->fresh();
}

it('recovers the real name from meta for a path that was all code', function () {
    $message = restoreAttachment('media/9_68d1a2f3b4c5d.pdf', 'Comprovante de Pagamento.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    // Accents, spaces and capitals all come back: the channel told us the name
    // at the time, and we kept it. Nothing about the old path held it.
    expect($message->fresh()->attachment)->toBe("media/{$message->id}/Comprovante de Pagamento.pdf")
        ->and(Storage::disk('local')->exists("media/{$message->id}/Comprovante de Pagamento.pdf"))->toBeTrue()
        ->and(Storage::disk('local')->exists('media/9_68d1a2f3b4c5d.pdf'))->toBeFalse();
});

it('strips a 12-hex token from a name that already had one', function () {
    $message = restoreAttachment('media/Contrato-de-Servico_a3f2b1c4d5e6.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe("media/{$message->id}/Contrato-de-Servico.pdf");
});

it('strips the message id when that was the suffix', function () {
    $message = restoreAttachment('media/relatorio.pdf');
    $coded = "media/relatorio_{$message->id}.pdf";
    Storage::disk('local')->move('media/relatorio.pdf', $coded);
    $message->update(['attachment' => $coded]);

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe("media/{$message->id}/relatorio.pdf");
});

it('leaves a real name that merely ends in digits', function () {
    // `_2026` is not this message's id and is not a 12-hex token, so it is part
    // of somebody's filename and stays.
    $message = restoreAttachment('media/Relatorio_2026.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('media/Relatorio_2026.pdf');
});

it('leaves an all-code name that has no recorded filename', function () {
    // There is no name to go back to, and inventing one is worse than the hash.
    $message = restoreAttachment('media/4812_68d1a2f3b4c5d.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('media/4812_68d1a2f3b4c5d.pdf');
});

it('skips a recorded name that is only the stored code again', function () {
    // The widget writes `meta.filename` as the basename of the path it just
    // stored, so on that channel the field *is* the UUID we want gone. Found by
    // a dry run against production, where this wanted to move thousands of
    // files to rewrite a name into itself.
    $message = restoreAttachment(
        'media/16e07057-c929-422c-993f-4790b89e6aee.png',
        '16e07057-c929-422c-993f-4790b89e6aee.png',
        MessageType::Image,
    );

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('media/16e07057-c929-422c-993f-4790b89e6aee.png');
});

it('skips a recorded name that is a bare uuid under a different path', function () {
    $message = restoreAttachment(
        'media/9_6a97404fbf895.png',
        '16e07057-c929-422c-993f-4790b89e6aee.png',
        MessageType::Image,
    );

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('media/9_6a97404fbf895.png');
});

it('leaves a widget upload in its own folder', function () {
    // `widget-uploads/{session}/` has its own lifecycle and its own orphan
    // sweep in media:purge. Relocating one into `media/` is a bigger action
    // than renaming it, and not one this command was asked to take.
    $path = 'widget-uploads/session-token/f1e2d3c4.png';
    $message = restoreAttachment($path, 'Foto do Produto.png', MessageType::Image);

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe($path);
});

it('still restores a real name the channel reported', function () {
    // The case the command exists for, straight from production.
    $message = restoreAttachment('media/9_6a970b43873bf.pdf', 'comprovante2026-09-01_142423.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)
        ->toBe("media/{$message->id}/comprovante2026-09-01_142423.pdf");
});

it('is idempotent', function () {
    $message = restoreAttachment('media/Fatura_a3f2b1c4d5e6.pdf');

    $this->artisan('media:restore-filenames')->assertSuccessful();
    $settled = $message->fresh()->attachment;

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe($settled)
        ->and(Storage::disk('local')->exists($settled))->toBeTrue();
});

it('refuses to overwrite a file already sitting at the target', function () {
    $message = restoreAttachment('media/Fatura_a3f2b1c4d5e6.pdf');
    Storage::disk('local')->put("media/{$message->id}/Fatura.pdf", 'someone else');

    $this->artisan('media:restore-filenames')->assertSuccessful();

    // The row keeps pointing at a file that is still there. A move that
    // clobbered the target would lose one of the two.
    expect($message->fresh()->attachment)->toBe('media/Fatura_a3f2b1c4d5e6.pdf')
        ->and(Storage::disk('local')->get("media/{$message->id}/Fatura.pdf"))->toBe('someone else');
});

it('leaves media that lives on somebody else\'s storage', function () {
    $message = restoreAttachment('media/x_a3f2b1c4d5e6.pdf');
    $message->update(['attachment' => 'https://cdn.example.com/Contrato_a3f2b1c4d5e6.pdf']);

    $this->artisan('media:restore-filenames')->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('https://cdn.example.com/Contrato_a3f2b1c4d5e6.pdf');
});

it('changes nothing on a dry run', function () {
    $message = restoreAttachment('media/Fatura_a3f2b1c4d5e6.pdf');

    $this->artisan('media:restore-filenames', ['--dry-run' => true])->assertSuccessful();

    expect($message->fresh()->attachment)->toBe('media/Fatura_a3f2b1c4d5e6.pdf')
        ->and(Storage::disk('local')->exists('media/Fatura_a3f2b1c4d5e6.pdf'))->toBeTrue();
});

it('moves the delta-sync cursor so dashboards hear about the new path', function () {
    $message = restoreAttachment('media/Fatura_a3f2b1c4d5e6.pdf');
    $before = $message->updated_at;

    $this->travel(2)->minutes();
    $this->artisan('media:restore-filenames')->assertSuccessful();

    // The SPA writes attachment URLs into IndexedDB and never asks for one
    // again, so this bump is the only way a client holding the old path stops
    // showing a broken bubble.
    expect($message->fresh()->updated_at->timestamp)->toBeGreaterThan($before->timestamp);
});
