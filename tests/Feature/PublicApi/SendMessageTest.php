<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadIntakeFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
});

function sendMessageFake(): Mockery\MockInterface
{
    $mock = Mockery::mock(SendMessageService::class);
    app()->instance(SendMessageService::class, $mock);

    return $mock;
}

it('gives every connection a random public id and shows it instead of an API key', function () {
    $owner = F::owner();
    $a = F::connection($owner);
    $b = F::connection($owner, Channel::Telegram, 'Bot');

    expect($a->public_id)->toStartWith(Connection::PUBLIC_ID_PREFIX)
        ->and(strlen($a->public_id))->toBe(strlen(Connection::PUBLIC_ID_PREFIX) + 16)
        ->and($a->public_id)->not->toBe($b->public_id)
        // ⚠️ The row id must not be reconstructable from the public one — stated
        // as the shape an encoded id would take, and deliberately NOT as "must
        // not contain the digits of the id". That earlier form asked a random
        // 16-character string never to contain "1" anywhere, because the row id
        // in this test is 1: false about a third of the time, so this test went
        // red on roughly one full-suite run in three for no reason at all. A
        // suffix of 16 characters drawn from [a-z0-9] being all digits has a
        // probability around 1e-9, which is a test and not a coin flip.
        ->and(substr($a->public_id, strlen(Connection::PUBLIC_ID_PREFIX)))->not->toMatch('/^0*\d+$/');

    $payload = (new ConnectionResource($a))->resolve();

    expect($payload['public_id'])->toBe($a->public_id)
        ->and($payload)->not->toHaveKey('api_key');
});

it('sends through the connection named by its public id', function () {
    $owner = F::owner();
    $connection = F::connection($owner, Channel::Telegram, 'Bot');

    sendMessageFake()->shouldReceive('sendMessage')->once()
        ->withArgs(fn (Connection $c, array $data) => $c->is($connection)
            && $data === ['chat_id' => '123456789', 'message' => 'Olá!'])
        ->andReturn([]);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/send-message', [
        'connection_id' => $connection->public_id,
        'chat_id' => '123456789',
        'message' => 'Olá!',
    ])->assertCreated()->assertJsonPath('message', 'Message sent successfully');
});

it('needs the API key and a connection of this workspace', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $plain = F::key($owner);

    $stranger = F::owner();
    $theirs = F::connection($stranger);

    sendMessageFake()->shouldNotReceive('sendMessage');

    $this->postJson('/api/v1/send-message', ['connection_id' => $connection->public_id, 'message' => 'Oi'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'api_key_missing');

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/send-message', ['message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection_id');

    // Another workspace's connection reads exactly like one that does not exist.
    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/send-message', ['connection_id' => $theirs->public_id, 'message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection_id');

    // The numeric primary key is not an id the API knows.
    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/send-message', ['connection_id' => (string) $connection->id, 'message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection_id');
});

it('refuses an inactive connection and a channel without a send path', function () {
    $owner = F::owner();
    $plain = F::key($owner);
    $paused = F::connection($owner, Channel::WhatsappApiway, 'Antigo', ConnectionStatus::Inactive);
    $mailbox = F::connection($owner, Channel::Email, 'Financeiro');

    sendMessageFake()->shouldNotReceive('sendMessage');

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/send-message', ['connection_id' => $paused->public_id, 'phone' => '5511987654321', 'message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'connection_inactive');

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/send-message', ['connection_id' => $mailbox->public_id, 'message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'channel_not_supported');
});
