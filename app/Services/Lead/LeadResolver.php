<?php

namespace App\Services\Lead;

use App\Enums\Lead\LeadSource;
use App\Enums\Lead\LeadStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Finds or opens the lead a conversation belongs to.
 *
 * The rule is one line, and it is one line *because* of the one-open-lead-per
 * -contact invariant:
 *
 *     does this contact have an open lead?  yes → attach.  no → open one.
 *
 * Without that invariant a person with two live cards makes "which one does
 * this message belong to?" unanswerable, and every downstream number inherits
 * the ambiguity.
 */
class LeadResolver
{
    public function __construct(
        private PipelineProvisioner $pipelines,
    ) {}

    /**
     * Attach the conversation to this contact's open lead, opening one if there
     * is none. Returns null when the conversation is not something that can be
     * sold to — a group, or a thread with no contact at all.
     */
    public function attach(Conversation $conversation): ?Lead
    {
        $contact = $conversation->getRelationValue('contact');

        // Groups are never leads. Nobody sells to a group chat, and the group
        // contact exists only so the inbox has something to render.
        if (! $contact || $contact->is_group) {
            return null;
        }

        // The connection is the authority on which workspace this belongs to —
        // it is what every other read path scopes by. Falling back to the
        // contact's own column covers the manual-creation path, where there is
        // no conversation yet.
        $tenantId = $conversation->getRelationValue('connection')?->tenant_id
            ?? $contact->tenant_id;

        if (! $tenantId) {
            return null;
        }

        $lead = $this->openLeadFor($contact)
            ?? $this->open($contact, $conversation, tenantId: $tenantId);

        if ($conversation->lead_id !== $lead->id) {
            $conversation->forceFill(['lead_id' => $lead->id])->saveQuietly();
        }

        return $lead;
    }

    public function openLeadFor(Contact $contact): ?Lead
    {
        return Lead::where('contact_id', $contact->id)
            ->where('status', LeadStatus::Open)
            ->first();
    }

    /**
     * Open a card for someone who does not have one.
     *
     * Races are real here — two inbound messages landing at once each dispatch
     * their own job — so the unique index on the generated open_contact_id is
     * the referee. A loser catches the violation and reads back the winner's
     * row rather than surfacing a 500 for what is, from the customer's side, a
     * perfectly ordinary "hello".
     */
    public function open(
        Contact $contact,
        ?Conversation $conversation = null,
        LeadSource $source = LeadSource::Inbound,
        ?int $tenantId = null,
    ): Lead {
        $tenantId ??= $contact->tenant_id;

        if (! $tenantId) {
            throw new \InvalidArgumentException("Contact {$contact->id} has no tenant to open a lead under.");
        }

        $pipeline = $this->pipelines->ensureDefault($tenantId);
        $stage = $pipeline->firstStage();

        if (! $stage) {
            throw new \RuntimeException("Pipeline {$pipeline->id} has no open stage to place a lead in.");
        }

        try {
            return DB::transaction(function () use ($contact, $conversation, $source, $pipeline, $stage, $tenantId) {
                $lead = Lead::create([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contact->id,
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stage->id,
                    'source' => $source,
                    'source_connection_id' => $conversation?->connection_id,
                    'stage_changed_at' => now(),
                ]);

                // The lead's own birth is a stage event: the funnel report reads
                // "entered Novo contato" from the log like every other move, so
                // the first column must not be a special case that is missing
                // from it.
                $lead->stageEvents()->create([
                    'tenant_id' => $lead->tenant_id,
                    'from_stage_id' => null,
                    'to_stage_id' => $stage->id,
                    'to_stage_name' => $stage->name,
                    'user_id' => null,
                    'created_at' => now(),
                ]);

                return $lead;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $this->openLeadFor($contact)
                ?? throw new \RuntimeException("Lost the race to open a lead for contact {$contact->id}, and the winner is gone.");
        }
    }
}
