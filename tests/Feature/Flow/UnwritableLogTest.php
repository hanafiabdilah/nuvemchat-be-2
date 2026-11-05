<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * A log file the app cannot write must not stop a flow.
 *
 * On 4 Nov 2026 the day's log file in one of the two PHP-FPM containers had
 * been created by root, so every Log::info() there threw. The first one on the
 * flow path runs right after the FlowState is created, and about half of all
 * new conversations got no greeting and no failure note until midnight.
 *
 * The fixture makes the day's log path a directory rather than a root-owned
 * file: opening it fails the same way for every user, root included, so the
 * test means the same thing on a laptop and in CI.
 *
 * ⚠️ Helpers are named for this file: Pest loads every test file into one
 * process, and a shared name is a fatal redeclare.
 */
function unwritableLogDir(): string
{
    $dir = storage_path('framework/testing/unwritable-log/'.uniqid());

    // RotatingFileHandler appends the date to the name. Both days are covered
    // so a run that crosses midnight still hits a path it cannot open.
    foreach ([now(), now()->addDay()] as $day) {
        File::ensureDirectoryExists($dir.'/laravel-'.$day->format('Y-m-d').'.log');
    }

    return $dir;
}

/** Point the default `stack` channel at a daily file that cannot be opened. */
function useUnwritableLog(string $dir): void
{
    config([
        'logging.default' => 'stack',
        'logging.channels.unwritable_daily' => [
            'driver' => 'daily',
            'path' => $dir.'/laravel.log',
            'level' => 'debug',
        ],
        'logging.channels.stack.channels' => ['unwritable_daily'],
    ]);

    Log::forgetChannel('stack');
}

/** start → message, on a WhatsApp Official connection with a working channel. */
function unwritableLogConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Greeting']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $greeting = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => [['message_type' => 'text', 'body' => 'Oie!', 'delay' => 0]]],
        'position_x' => 100,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $greeting->id, 'condition_value' => null]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511988887777',
        'name' => 'Ana',
        'username' => '5511988887777',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511988887777',
        'status' => ConversationStatus::Pending,
    ]);
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/unwritable-log'));
});

it('reproduces the incident: a stack that does not ignore exceptions throws', function () {
    $dir = unwritableLogDir();

    config([
        'logging.channels.unwritable_daily' => ['driver' => 'daily', 'path' => $dir.'/laravel.log', 'level' => 'debug'],
        'logging.channels.strict_stack' => ['driver' => 'stack', 'channels' => ['unwritable_daily'], 'ignore_exceptions' => false],
    ]);

    // Without this the test below would pass for the wrong reason: a fixture
    // that can be written proves nothing about one that cannot.
    expect(fn () => Log::channel('strict_stack')->info('FlowExecutor: Executing node'))
        ->toThrow(UnexpectedValueException::class);
});

it('keeps logging from throwing when the file cannot be written', function () {
    useUnwritableLog(unwritableLogDir());

    expect(config('logging.channels.stack.ignore_exceptions'))->toBeTrue();

    Log::info('FlowExecutor: Executing node');
    Log::error('WhatsappApiwayHandler: failed to start flow');

    expect(true)->toBeTrue();
});

it('still greets the customer when the log file cannot be written', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    $conversation = unwritableLogConversation();
    useUnwritableLog(unwritableLogDir());

    FlowRunner::start($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->pluck('body')
        ->all();

    expect($sent)->toBe(['Oie!']);

    // The flow moved past its Start node: this is the state 53 conversations
    // were left in on 4 Nov, parked on Start with nothing sent.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->currentNode->type)->toBe(NodeType::Message)
        ->and($state->status)->toBe(FlowStateStatus::Completed);
});
