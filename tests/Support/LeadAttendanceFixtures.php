<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Lead\LeadSource;
use App\Enums\Lead\LeadStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Setup for the "answered → Atendidos" suite. A class rather than Pest helper
 * functions, which are global and collide across test files.
 */
final class LeadAttendanceFixtures
{
    public static function owner(): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        foreach (['leads.view', 'leads.update', 'lead-pipelines.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    public static function connection(User $owner, Channel $channel = Channel::WhatsappApiway): Connection
    {
        return Connection::create([
            'tenant_id' => $owner->tenant_id,
            'channel' => $channel,
            'name' => $channel->value,
            'color' => '#22c55e',
            'status' => ConnectionStatus::Active,
        ]);
    }

    public static function contact(User $owner, string $externalId, Channel $channel = Channel::WhatsappApiway): Contact
    {
        return Contact::create([
            'tenant_id' => $owner->tenant_id,
            'external_id' => $externalId,
            'name' => 'Contato '.$externalId,
            'channel' => $channel,
        ]);
    }

    /** Creating it runs the observer, which (sync queue) opens the card. */
    public static function conversation(Connection $connection, Contact $contact): Conversation
    {
        return Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $contact->external_id,
            'status' => ConversationStatus::Pending,
        ]);
    }

    /**
     * An outgoing message, stamped with its author the way the send routes do
     * it: create first, `sent_by_user_id` right after. Null author = flow/AI.
     */
    public static function reply(Conversation $conversation, ?User $agent, array $attributes = []): Message
    {
        $message = $conversation->messages()->create(array_merge([
            'external_id' => 'out.'.Str::random(10),
            'sender_type' => SenderType::Outgoing,
            'message_type' => MessageType::Text,
            'body' => 'Olá!',
            'sent_at' => now(),
        ], $attributes));

        if ($agent) {
            $message->update(['sent_by_user_id' => $agent->id]);
        }

        return $message;
    }

    public static function inbound(Conversation $conversation): Message
    {
        return $conversation->messages()->create([
            'external_id' => 'in.'.Str::random(10),
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'Oi',
            'sent_at' => now(),
        ]);
    }

    /** The contact's open card, freshly read. */
    public static function lead(Contact $contact): ?Lead
    {
        return Lead::where('contact_id', $contact->id)->where('status', LeadStatus::Open)->with('stage')->first();
    }

    /** @return array<string, LeadStage> the default funnel, by name */
    public static function stages(int $tenantId): array
    {
        return app(PipelineProvisioner::class)
            ->ensureDefault($tenantId)
            ->stages()
            ->orderBy('position')
            ->get()
            ->keyBy('name')
            ->all();
    }

    /** A card on an e-mail thread, as leads were opened before e-mail was excluded. */
    public static function emailLead(User $owner, Connection $mailbox, string $address): Lead
    {
        $contact = self::contact($owner, $address, Channel::Email);
        $conversation = self::conversation($mailbox, $contact);

        $lead = app(LeadResolver::class)->open($contact, $conversation, LeadSource::Inbound, $owner->tenant_id);
        $conversation->forceFill(['lead_id' => $lead->id])->saveQuietly();

        return $lead;
    }
}
