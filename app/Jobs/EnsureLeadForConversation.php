<?php

namespace App\Jobs;

use App\Enums\Billing\Feature;
use App\Events\LeadUpdated;
use App\Models\Conversation;
use App\Services\Billing\SubscriptionGate;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\LeadSettings;
use App\Services\Lead\TemperatureScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Opens (or finds) the lead behind a new conversation.
 *
 * Queued rather than inline because a webhook must never wait on work the
 * customer is not waiting for — the same rule inbound media downloads follow.
 * Everything here is idempotent: the resolver returns the existing open lead if
 * there is one, so a retry costs a couple of selects and changes nothing.
 */
class EnsureLeadForConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $conversationId,
    ) {}

    public function handle(
        LeadResolver $resolver,
        TemperatureScorer $scorer,
        SubscriptionGate $gate,
    ): void {
        $conversation = Conversation::with(['contact', 'connection'])->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $tenant = $conversation->getRelationValue('connection')?->tenant;

        if (! $tenant) {
            return;
        }

        // A workspace without the CRM feature should not quietly accumulate
        // rows it cannot see; if they buy the plan later, leads start from that
        // day rather than pretending to have a history. Mirrors
        // EnsureFeatureEnabled, master switch included, so the queue and the
        // routes can never disagree about who has the funnel.
        if (config('services.mercadopago.enforce') && ! $gate->feature($tenant, Feature::Crm->value)) {
            return;
        }

        // A workspace that works its funnel by hand should not have cards
        // appearing behind it.
        if (! LeadSettings::for($tenant)->autoCreate) {
            return;
        }

        $lead = $resolver->attach($conversation);

        if (! $lead) {
            return;
        }

        $scorer->apply($lead);

        // A lead that exists but whose board did not light up is a small
        // problem; a webhook-driven job that keeps failing and retrying because
        // the websocket server is unreachable is a much bigger one. The card is
        // already saved, and any dashboard fetch picks it up.
        try {
            broadcast(new LeadUpdated($lead));
        } catch (\Throwable $e) {
            Log::warning('Lead created but could not be broadcast', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Lead attached to conversation', [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $lead->contact_id,
            'tenant_id' => $tenant->id,
        ]);
    }
}
