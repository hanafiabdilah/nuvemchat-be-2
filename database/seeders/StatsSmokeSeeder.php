<?php

namespace Database\Seeders;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnStatus;
use App\Enums\Conversation\Status as ConvStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\{Connection, Contact, Conversation, Message, Tag, Tenant, User};
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\{Permission, Role};
use Illuminate\Database\Seeder;

/**
 * Local-only fixture for looking at the statistics page: one tenant, three
 * agents, three connections and ~260 conversations spread over 40 days, with a
 * realistic mix of fast replies, slow replies, threads nobody answered, and
 * bot-only threads.
 *
 * Never wired into DatabaseSeeder — run it by hand
 * (`php artisan db:seed --class=StatsSmokeSeeder`) and only on a dev database.
 * It owns its three e-mail addresses and deletes their tenant before rebuilding,
 * so re-running refreshes the data instead of duplicating it.
 */
class StatsSmokeSeeder extends Seeder
{
    public function run(): void
    {

// Idempotent: this seeder is a throwaway smoke fixture, so it owns and
// clears its own tenant before rebuilding it.
foreach (User::whereIn('email', ['ana@example.com', 'bruno@example.com', 'carla@example.com'])->get() as $existing) {
    if ($existing->tenant_id) {
        Tenant::where('id', $existing->tenant_id)->delete();
    }
    $existing->forceDelete();
}

$owner = User::create(['name' => 'Ana Souza', 'email' => 'ana@example.com', 'password' => bcrypt('secret')]);
$tenant = Tenant::create(['user_id' => $owner->id]);
$owner->forceFill(['tenant_id' => $tenant->id])->save();

$role = Role::findOrCreate('owner', 'web');
foreach (['statistics.tenant.view', 'statistics.agents.view'] as $p) {
    $role->givePermissionTo(Permission::findOrCreate($p, 'web'));
}
$owner->assignRole($role);

$bruno = User::create(['name' => 'Bruno Lima', 'email' => 'bruno@example.com', 'password' => bcrypt('secret'), 'tenant_id' => $tenant->id]);
$carla = User::create(['name' => 'Carla Dias', 'email' => 'carla@example.com', 'password' => bcrypt('secret'), 'tenant_id' => $tenant->id]);

$connections = [];
foreach ([[Channel::WhatsappApiway, 'WhatsApp Vendas'], [Channel::Telegram, 'Telegram Suporte'], [Channel::Instagram, 'Instagram']] as [$channel, $name]) {
    $connections[] = Connection::create([
        'tenant_id' => $tenant->id, 'channel' => $channel, 'name' => $name,
        'color' => '#22c55e', 'status' => ConnStatus::Active,
    ]);
}

$tags = collect(['Orçamento', 'Suporte técnico', 'Reclamação'])
    ->map(fn ($n) => Tag::create(['tenant_id' => $tenant->id, 'name' => $n, 'color' => '#3b82f6']));

$agents = [$owner->id, $bruno->id, $carla->id];
mt_srand(7);

for ($i = 0; $i < 260; $i++) {
    $connection = $connections[mt_rand(0, 2)];
    // Weight toward business hours so the heatmap has a real shape.
    $openedAt = Carbon::now()
        ->subDays(mt_rand(0, 40))
        ->setTime(mt_rand(0, 10) < 8 ? mt_rand(9, 19) : mt_rand(0, 23), mt_rand(0, 59));

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511' . str_pad((string) mt_rand(1, 120), 9, '0', STR_PAD_LEFT),
        'name' => 'Cliente ' . $i,
        'channel' => $connection->channel,
    ]);
    $contact->forceFill(['created_at' => $openedAt])->save();

    $conversation = Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => $contact->external_id, 'status' => ConvStatus::Pending,
    ]);
    $conversation->forceFill(['created_at' => $openedAt, 'updated_at' => $openedAt])->save();

    $inbound = Message::create([
        'conversation_id' => $conversation->id, 'sender_type' => SenderType::Incoming,
        'message_type' => mt_rand(0, 9) < 8 ? MessageType::Text : MessageType::Image,
        'body' => 'Olá, preciso de ajuda', 'sent_at' => $openedAt,
    ]);
    $inbound->forceFill(['created_at' => $openedAt, 'updated_at' => $openedAt])->save();

    $roll = mt_rand(1, 100);

    if ($roll <= 12) {
        continue; // never answered
    }

    if ($roll <= 30) {
        // Answered by automation only.
        $botAt = $openedAt->copy()->addSeconds(mt_rand(2, 20));
        $bot = Message::create([
            'conversation_id' => $conversation->id, 'sender_type' => SenderType::Outgoing,
            'message_type' => MessageType::Text, 'body' => 'Sou o assistente virtual', 'sent_at' => $botAt,
        ]);
        $bot->forceFill(['created_at' => $botAt, 'updated_at' => $botAt, 'sent_by_ai_hub_agent_id' => null])->save();
        $conversation->forceFill(['status' => ConvStatus::AiHandling])->save();
        continue;
    }

    $agentId = $agents[mt_rand(0, 2)];
    $wait = match (true) {
        $roll <= 55 => mt_rand(10, 55),
        $roll <= 78 => mt_rand(60, 290),
        $roll <= 90 => mt_rand(300, 1700),
        $roll <= 96 => mt_rand(1800, 7000),
        default => mt_rand(7300, 40000),
    };
    $replyAt = $openedAt->copy()->addSeconds($wait);

    $reply = Message::create([
        'conversation_id' => $conversation->id, 'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text, 'body' => 'Claro, vou verificar', 'sent_at' => $replyAt,
        'sent_by_user_id' => $agentId,
    ]);
    $reply->forceFill(['created_at' => $replyAt, 'updated_at' => $replyAt])->save();

    if (mt_rand(0, 9) < 2) {
        $reply->forceFill(['error' => 'Message failed to send: (#131047) Re-engagement message'])->save();
    }

    $conversation->forceFill(['user_id' => $agentId, 'status' => ConvStatus::Active])->save();

    if (mt_rand(0, 9) < 3) {
        $conversation->tags()->attach($tags[mt_rand(0, 2)]->id);
    }

    if (mt_rand(0, 9) < 6) {
        $closedAt = $replyAt->copy()->addSeconds(mt_rand(600, 90000));
        $conversation->forceFill([
            'status' => ConvStatus::Resolved,
            'resolved_at' => $closedAt,
            'resolved_by_user_id' => $agentId,
        ])->save();
    }
}

$token = $owner->createToken('stats-smoke')->plainTextToken;
echo "TOKEN={$token}\n";

    }
}
