<?php

namespace Tests\Support;

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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the broadcast suites. Lives outside tests/Feature so Pest
 * does not try to run it, and as a class rather than loose functions so five
 * test files can share it without colliding in the one process Pest loads them
 * all into.
 */
class BroadcastFixtures
{
    /** A tenant owner holding every broadcast permission. */
    public static function user(array $permissions = ['broadcasts.view', 'broadcasts.create', 'broadcasts.send', 'broadcasts.delete']): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $role = Role::findOrCreate('broadcaster-' . $tenant->id, 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    public static function connection(User $user, Channel $channel = Channel::WhatsappOfficial): Connection
    {
        return Connection::create([
            'tenant_id' => $user->tenant_id,
            'channel' => $channel,
            'name' => $channel->value . ' line',
            'color' => '#25D366',
            'status' => ConnectionStatus::Active,
            'credentials' => [
                'phone_number_id' => 'phone-1',
                'access_token' => 'wa-token',
                'business_account_id' => 'waba-1',
                'instance_id' => 'inst-1',
                'token' => 'apiway-token',
                'bot_token' => 'tg-token',
            ],
        ]);
    }

    public static function contact(User $user, string $externalId, string $name, Channel $channel = Channel::WhatsappOfficial): Contact
    {
        return Contact::create([
            'tenant_id' => $user->tenant_id,
            'external_id' => $externalId,
            'channel' => $channel,
            'name' => $name,
        ]);
    }

    /** An open thread with one inbound message, i.e. a session window that is open. */
    public static function conversationWithInbound(Connection $connection, Contact $contact, ?int $sentAt = null): Conversation
    {
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $contact->external_id,
            'status' => ConversationStatus::Active,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'external_id' => 'in-' . $contact->id,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'oi',
            'sent_at' => $sentAt ?? now()->timestamp,
        ]);

        return $conversation->fresh();
    }

    /** The request body for a WhatsApp Official template campaign. */
    public static function templateCampaign(Connection $connection, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Promo de agosto',
            'connection_id' => $connection->id,
            'content_type' => 'template',
            'payload' => [
                'template_name' => 'promo_agosto',
                'language' => 'pt_BR',
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '{{contact.first_name}}']]],
                ],
            ],
        ], $overrides);
    }
}
