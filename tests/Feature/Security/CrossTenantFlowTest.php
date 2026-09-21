<?php

use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\FlowState;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IntegrationFixtures as Fx;

/**
 * `flow_id` was validated with a bare `exists:flows,id`, and
 * FlowExecutor::startFlow() loads the flow straight off connections.flow_id
 * without asking whose it is. So one workspace could point its own connection
 * at another's flow and run it: every message node would be delivered into the
 * attacker's inbox (reading the whole script out), the payment node would raise
 * real charges on the victim's gateway, and the AI and HTTP nodes would run on
 * their behalf.
 */
uses(RefreshDatabase::class);

it('refuses to attach another workspace’s flow to a connection', function () {
    $mine = Fx::tenant();
    $theirs = Fx::tenant();
    $stranger = Fx::flow($theirs, 'Roteiro de vendas');

    Sanctum::actingAs(Fx::user($mine, ['connections.create', 'connections.update']));

    $this->postJson('/api/connections', [
        'channel' => 'telegram',
        'name' => 'Meu canal',
        'flow_id' => $stranger->id,
    ])->assertStatus(422)->assertJsonValidationErrors('flow_id');

    expect(Connection::count())->toBe(0);
});

it('refuses to switch an existing connection onto another workspace’s flow', function () {
    $mine = Fx::tenant();
    $theirs = Fx::tenant();
    $stranger = Fx::flow($theirs, 'Roteiro de vendas');

    $connection = Connection::create([
        'tenant_id' => $mine->id,
        'channel' => App\Enums\Connection\Channel::Telegram,
        'name' => 'Meu canal',
        'status' => App\Enums\Connection\Status::Active,
        'credentials' => ['token' => 'bot-token'],
    ]);

    Sanctum::actingAs(Fx::user($mine, ['connections.update']));

    $this->putJson("/api/connections/{$connection->id}", [
        'name' => 'Meu canal',
        'flow_id' => $stranger->id,
    ])->assertStatus(422)->assertJsonValidationErrors('flow_id');

    expect($connection->fresh()->flow_id)->toBeNull();
});

it('will not run a cross-tenant flow even if the row already points at one', function () {
    $mine = Fx::tenant();
    $theirs = Fx::tenant();

    // Their flow, with something worth stealing in it.
    $stranger = Fx::flow($theirs, 'Roteiro de vendas');
    $secret = Fx::say($stranger, 'Nosso desconto interno é de 40%.');
    Fx::edge(Fx::start($stranger), $secret);

    // A row written before the validation was scoped.
    $flow = Fx::flow($mine);
    $conversation = Fx::conversation($mine, $flow);
    $conversation->connection->forceFill(['flow_id' => $stranger->id])->save();

    (new FlowExecutor)->startFlow($conversation->fresh());

    // Refused rather than repaired: the connection is somebody's live inbox,
    // and quietly detaching its automation would be a second outage.
    expect(FlowState::where('conversation_id', $conversation->id)->exists())->toBeFalse()
        ->and(Fx::sentTexts($conversation))->toBe([]);
});
