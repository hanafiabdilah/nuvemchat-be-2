<?php

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\V1\SendMessage\OutgoingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LeadIntakeFixtures as F;

/*
 * POST /v1/send-message with `type` + `media_url`: what each channel is handed,
 * and what is refused before any channel is called.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
});

/** A connection carrying the credentials its V1 handler reads. */
function mediaApiConnection(User $owner, Channel $channel, array $credentials): Connection
{
    $connection = F::connection($owner, $channel, 'Canal '.$channel->value);
    $connection->update(['credentials' => $credentials]);

    return $connection;
}

function mediaApiSend(User $owner, array $body)
{
    return test()->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/send-message', $body);
}

it('still sends a text when the body has no type', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T1']]])]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::WhatsappOfficial, ['access_token' => 'tok', 'phone_number_id' => '111']);

    mediaApiSend($owner, ['connection_id' => $connection->public_id, 'to' => '5511987654321', 'message' => 'Olá!'])
        ->assertCreated();

    Http::assertSent(fn (Request $r) => $r['type'] === 'text' && $r['text'] === ['body' => 'Olá!'] && $r['to'] === '5511987654321');
});

it('hands WhatsApp Official the link, the caption, the file name and the voice-note flag', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.M1']]])]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::WhatsappOfficial, ['access_token' => 'tok', 'phone_number_id' => '111']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'to' => '5511987654321',
        'type' => 'image',
        'media_url' => 'https://cdn.example.com/produto.png',
        'message' => 'Seu pedido',
    ])->assertCreated();

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'to' => '5511987654321',
        'type' => 'document',
        'media_url' => 'https://cdn.example.com/download?id=42',
        'filename' => 'Boleto março.pdf',
        'message' => 'Segue o boleto',
    ])->assertCreated();

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'to' => '5511987654321',
        'type' => 'audio',
        'media_url' => 'https://cdn.example.com/aviso.ogg',
    ])->assertCreated();

    Http::assertSent(fn (Request $r) => $r['type'] === 'image'
        && $r['image'] === ['link' => 'https://cdn.example.com/produto.png', 'caption' => 'Seu pedido']);

    Http::assertSent(fn (Request $r) => $r['type'] === 'document'
        && $r['document'] === ['link' => 'https://cdn.example.com/download?id=42', 'caption' => 'Segue o boleto', 'filename' => 'Boleto março.pdf']);

    Http::assertSent(fn (Request $r) => $r['type'] === 'audio'
        && $r['audio'] === ['link' => 'https://cdn.example.com/aviso.ogg', 'voice' => true]);
});

it('sends API Way media to the endpoint named after its kind', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-1']])]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::WhatsappApiway, ['token' => 'tok', 'instance_id' => 'inst-1']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'type' => 'document',
        'media_url' => 'https://cdn.example.com/files/Contrato%20de%20Servi%C3%A7o.pdf',
        'message' => 'Contrato',
    ])->assertCreated();

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'type' => 'video',
        'media_url' => 'https://cdn.example.com/demo.mp4',
    ])->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/message/send-document?instanceId=inst-1')
        && $r['document'] === 'https://cdn.example.com/files/Contrato%20de%20Servi%C3%A7o.pdf'
        && $r['fileName'] === 'Contrato de Serviço.pdf'
        && $r['extension'] === 'pdf'
        && $r['caption'] === 'Contrato');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/message/send-video')
        && $r['video'] === 'https://cdn.example.com/demo.mp4'
        && ! array_key_exists('caption', $r->data()));
});

it('sends Messenger and Instagram files as attachments, a document as a file', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'm_1']),
        'graph.instagram.com/*' => Http::response(['message_id' => 'ig_1']),
    ]);
    $owner = F::owner();
    $messenger = mediaApiConnection($owner, Channel::Messenger, ['access_token' => 'tok']);
    $instagram = mediaApiConnection($owner, Channel::Instagram, ['access_token' => 'tok']);

    mediaApiSend($owner, [
        'connection_id' => $messenger->public_id,
        'recipient_id' => '24000000000000001',
        'type' => 'document',
        'media_url' => 'https://cdn.example.com/catalogo.pdf',
    ])->assertCreated();

    mediaApiSend($owner, [
        'connection_id' => $instagram->public_id,
        'recipient_id' => '17841400000000000',
        'type' => 'video',
        'media_url' => 'https://cdn.example.com/reel.mp4',
    ])->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
        && $r['messaging_type'] === 'RESPONSE'
        && $r['message'] === ['attachment' => ['type' => 'file', 'payload' => ['url' => 'https://cdn.example.com/catalogo.pdf', 'is_reusable' => true]]]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.instagram.com')
        && $r['message']['attachment']['type'] === 'video'
        && $r['message']['attachment']['payload']['url'] === 'https://cdn.example.com/reel.mp4');
});

it('downloads the file for Discord and uploads it under the name given', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        'discord.com/api/v10/users/@me/channels' => Http::response(['id' => 'dm-1']),
        'discord.com/api/v10/channels/dm-1/messages' => Http::response(['id' => 'msg-1']),
    ]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::Discord, ['token' => 'bot-token']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'recipient_id' => '777888999000111222',
        'type' => 'document',
        'media_url' => 'https://cdn.example.com/report',
        'filename' => 'Relatorio.pdf',
        'message' => 'Relatório do mês',
    ])->assertCreated();

    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), '/channels/dm-1/messages') || ! $r->isMultipart()) {
            return false;
        }

        $parts = collect($r->data())->keyBy('name');
        $payload = json_decode($parts['payload_json']['contents'], true);

        return $parts['files[0]']['filename'] === 'Relatorio.pdf'
            && $parts['files[0]']['contents'] === '%PDF-1.4 fake'
            && $payload['content'] === 'Relatório do mês'
            && $payload['attachments'][0]['filename'] === 'Relatorio.pdf';
    });
});

it('refuses a file Discord would have to download but cannot', function () {
    Http::fake(['cdn.example.com/*' => Http::response('', 404)]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::Discord, ['token' => 'bot-token']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'recipient_id' => '777888999000111222',
        'type' => 'image',
        'media_url' => 'https://cdn.example.com/missing.png',
    ])->assertUnprocessable()->assertJsonValidationErrors('media_url');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'discord.com'));
});

it('sends a TikTok image as an upload and keeps a copy in the thread', function () {
    Storage::fake(MediaStorage::diskName());
    Http::fake([
        'cdn.example.com/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        'business-api.tiktok.com/open_api/v1.3/business/message/media/upload/*' => Http::response(['code' => 0, 'data' => ['media_id' => 'media-9']]),
        'business-api.tiktok.com/open_api/v1.3/business/message/send/*' => Http::response(['code' => 0, 'data' => ['message' => ['message_id' => 'tt-msg-1']]]),
    ]);
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::TikTok, [
        'business_id' => 'BIZ-1',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addDay()->toDateTimeString(),
        'refresh_token_expires_at' => now()->addDays(30)->toDateTimeString(),
    ]);
    $contact = Contact::create(['tenant_id' => $owner->tenant_id, 'external_id' => 'tt-user', 'channel' => Channel::TikTok, 'name' => 'bia']);
    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => 'tt-conv-1',
        'status' => ConversationStatus::Active,
    ]);
    $conversation->messages()->create([
        'external_id' => 'in-1',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ]);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'conversation_id' => 'tt-conv-1',
        'type' => 'image',
        'media_url' => 'https://cdn.example.com/cupom.png',
    ])->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/message/send/')
        && $r['message_type'] === 'IMAGE'
        && $r['image'] === ['media_id' => 'media-9']);

    $sent = $conversation->messages()->where('external_id', 'tt-msg-1')->sole();

    expect($sent->message_type)->toBe(MessageType::Image)
        ->and($sent->attachment)->toStartWith('media/'.$sent->id.'/')
        ->and($sent->attachment)->toEndWith('.png')
        ->and(MediaStorage::disk()->get($sent->attachment))->toBe('PNGBYTES');
});

it('asks for type when a file is named, and refuses a body that mixes them up', function () {
    Http::fake();
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::WhatsappOfficial, ['access_token' => 'tok', 'phone_number_id' => '111']);
    $base = ['connection_id' => $connection->public_id, 'to' => '5511987654321'];

    // A media_url with no type would otherwise be read as a text and the file dropped.
    mediaApiSend($owner, $base + ['media_url' => 'https://cdn.example.com/a.png', 'message' => 'Oi'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    mediaApiSend($owner, $base + ['type' => 'text', 'message' => 'Oi', 'media_url' => 'https://cdn.example.com/a.png'])
        ->assertUnprocessable()->assertJsonValidationErrors('media_url');

    mediaApiSend($owner, $base + ['type' => 'image', 'message' => 'Oi'])
        ->assertUnprocessable()->assertJsonValidationErrors('media_url');

    mediaApiSend($owner, $base + ['type' => 'sticker', 'media_url' => 'https://cdn.example.com/a.webp'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    mediaApiSend($owner, $base + ['type' => 'image', 'media_url' => 'ftp://cdn.example.com/a.png'])
        ->assertUnprocessable()->assertJsonValidationErrors('media_url');

    Http::assertNothingSent();
});

it('refuses a caption where the channel has none, instead of dropping it', function () {
    Http::fake();
    $owner = F::owner();
    $instagram = mediaApiConnection($owner, Channel::Instagram, ['access_token' => 'tok']);
    $official = mediaApiConnection($owner, Channel::WhatsappOfficial, ['access_token' => 'tok', 'phone_number_id' => '111']);

    mediaApiSend($owner, [
        'connection_id' => $instagram->public_id,
        'recipient_id' => '17841400000000000',
        'type' => 'image',
        'media_url' => 'https://cdn.example.com/a.png',
        'message' => 'Legenda',
    ])->assertUnprocessable()->assertJsonValidationErrors('message');

    mediaApiSend($owner, [
        'connection_id' => $official->public_id,
        'to' => '5511987654321',
        'type' => 'audio',
        'media_url' => 'https://cdn.example.com/a.mp3',
        'message' => 'Legenda',
    ])->assertUnprocessable()->assertJsonValidationErrors('message');

    Http::assertNothingSent();
});

it('refuses on TikTok what TikTok cannot send, before looking for the conversation', function () {
    Http::fake();
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::TikTok, ['access_token' => 'tok']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'conversation_id' => 'tt-conv-1',
        'type' => 'video',
        'media_url' => 'https://cdn.example.com/a.mp4',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['type' => 'O TikTok não envia vídeo pela API. Tipos aceitos: texto, imagem.']);

    Http::assertNothingSent();
});

it('never fetches or forwards a media_url inside our own network', function () {
    Http::fake();
    $owner = F::owner();
    $connection = mediaApiConnection($owner, Channel::WhatsappOfficial, ['access_token' => 'tok', 'phone_number_id' => '111']);

    mediaApiSend($owner, [
        'connection_id' => $connection->public_id,
        'to' => '5511987654321',
        'type' => 'image',
        'media_url' => 'http://169.254.169.254/latest/meta-data.png',
    ])->assertUnprocessable()->assertJsonValidationErrors('media_url');

    Http::assertNothingSent();
});

it('keeps the capability tables to the channels the endpoint sends through', function () {
    foreach (\App\Http\Controllers\Api\V1\SendMessageController::CHANNELS as $channel) {
        expect(OutgoingMessage::mediaTypes($channel))->not->toBeEmpty()
            ->and(array_diff(OutgoingMessage::captionTypes($channel), OutgoingMessage::mediaTypes($channel)))->toBe([]);
    }
});
