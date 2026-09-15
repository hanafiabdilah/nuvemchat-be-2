<?php

namespace App\Services\Webhooks;

use App\Enums\Lead\StageKind;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStage;
use App\Models\LeadStageEvent;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Contact\ContactIdentity;
use App\Services\Lead\LeadAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns what happens to a lead into webhook events.
 *
 * Only leads that came in through the public API (POST /v1/leads) are
 * announced: the receiving system only knows those, by their `reference`, and
 * the rest of the workspace's funnel — people who wrote in on their own — has
 * no business leaving Pingly.
 *
 * Hooked to three model events rather than to the screens that change leads,
 * because each of these changes has several writers (drag, dialog, flow node,
 * the API, the automatic move to Atendidos, accept / transfer / take-over):
 *
 *   LeadStageEvent created        → lead.stage_changed, or lead.won / lead.lost
 *                                   when the move closes the lead
 *   Lead owner_id set             → lead.assigned (assignment.type = owner)
 *   Conversation user_id set      → lead.assigned (assignment.type = conversation)
 *
 * Every event carries the conversation block the receiver uses as evidence the
 * lead was actually worked: how many messages people from the team sent and
 * when the first one went out — the same "human reply" rule the funnel and the
 * statistics use (LeadAttendance::humanReplies).
 *
 * Emitted after the surrounding transaction commits, and never allowed to
 * throw: a receiver being down must not undo a stage move.
 */
final class LeadWebhooks
{
    public function __construct(
        private WebhookDispatcher $dispatcher,
    ) {}

    public static function register(): void
    {
        LeadStageEvent::created(function (LeadStageEvent $event) {
            // A card's birth is logged as a stage event too; it is not a change.
            if ($event->from_stage_id !== null) {
                self::safely(fn (self $webhooks) => $webhooks->stageChanged($event));
            }
        });

        Lead::updated(function (Lead $lead) {
            if ($lead->wasChanged('owner_id') && $lead->owner_id) {
                self::safely(fn (self $webhooks) => $webhooks->ownerAssigned($lead));
            }
        });

        Conversation::updated(function (Conversation $conversation) {
            if ($conversation->wasChanged('user_id') && $conversation->user_id && $conversation->lead_id) {
                self::safely(fn (self $webhooks) => $webhooks->conversationAssigned($conversation));
            }
        });
    }

    public function stageChanged(LeadStageEvent $stageEvent): void
    {
        $lead = Lead::find($stageEvent->lead_id);
        $to = LeadStage::find($stageEvent->to_stage_id);

        if (! $lead || ! $to) {
            return;
        }

        $event = match ($to->kind) {
            StageKind::Won => WebhookEvents::LEAD_WON,
            StageKind::Lost => WebhookEvents::LEAD_LOST,
            default => WebhookEvents::LEAD_STAGE_CHANGED,
        };

        $this->emit($lead, $event, [
            'previous_stage' => $this->stage(LeadStage::find($stageEvent->from_stage_id)),
            // Null = Pingly did it (the automatic move to Atendidos, the stale
            // sweep, a flow, or the API closing the lead).
            'changed_by' => $this->user($stageEvent->user_id),
        ]);
    }

    public function ownerAssigned(Lead $lead): void
    {
        $this->emit($lead, WebhookEvents::LEAD_ASSIGNED, [
            'assignment' => ['type' => 'owner', 'assigned_to' => $this->user($lead->owner_id)],
        ]);
    }

    public function conversationAssigned(Conversation $conversation): void
    {
        $lead = Lead::find($conversation->lead_id);

        if ($lead) {
            $this->emit($lead, WebhookEvents::LEAD_ASSIGNED, [
                'assignment' => ['type' => 'conversation', 'assigned_to' => $this->user($conversation->user_id)],
            ], $conversation);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function emit(Lead $lead, string $event, array $extra, ?Conversation $conversation = null): void
    {
        // Cheap exits first: most workspaces have no webhook at all.
        if (! WebhookEndpoint::where('tenant_id', $lead->tenant_id)->where('is_active', true)->exists()) {
            return;
        }

        $intakes = LeadIntake::where('lead_id', $lead->id)->latest('id');

        if (! (clone $intakes)->exists()) {
            return;
        }

        $conversation ??= Conversation::find((clone $intakes)->whereNotNull('conversation_id')->value('conversation_id'))
            ?? Conversation::where('lead_id', $lead->id)->latest('id')->first();

        $lead->loadMissing(['stage', 'contact']);

        $this->dispatcher->emit($lead->tenant_id, $event, [
            'lead' => [
                'id' => $lead->id,
                'reference' => (clone $intakes)->whereNotNull('reference')->value('reference'),
                'title' => $lead->displayTitle(),
                'status' => $lead->status->value,
                'value' => $lead->value !== null ? (float) $lead->value : null,
                'currency' => $lead->currency,
                'lost_reason' => $lead->lost_reason,
                'stage' => $this->stage($lead->getRelationValue('stage')),
                'owner' => $this->user($lead->owner_id),
                'contact' => $lead->contact ? [
                    'id' => $lead->contact->id,
                    'name' => $lead->contact->name,
                    'phone' => ContactIdentity::for($lead->contact)['phone'] ?? null,
                ] : null,
                'created_at' => $this->iso($lead->created_at),
                'closed_at' => $this->iso($lead->closed_at),
            ],
            ...$extra,
            'conversation' => $conversation ? $this->conversation($conversation) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function conversation(Conversation $conversation): array
    {
        $replies = LeadAttendance::humanReplies(Message::query()->toBase())
            ->where('messages.conversation_id', $conversation->id);

        $first = (clone $replies)->min('messages.created_at');

        return [
            'id' => $conversation->id,
            'connection_id' => Connection::whereKey($conversation->connection_id)->value('public_id'),
            'status' => $conversation->status?->value,
            'assigned_to' => $this->user($conversation->user_id),
            'agent_messages_count' => (clone $replies)->count(),
            'first_agent_message_at' => $first ? $this->iso(Carbon::parse($first, config('app.timezone'))) : null,
        ];
    }

    /** @return array{id: int, name: string, kind: string}|null */
    private function stage(?LeadStage $stage): ?array
    {
        return $stage ? ['id' => $stage->id, 'name' => $stage->name, 'kind' => $stage->kind->value] : null;
    }

    /** @return array{id: int, name: string, email: string}|null */
    private function user(?int $userId): ?array
    {
        $user = $userId ? User::find($userId) : null;

        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
    }

    private function iso(mixed $moment): ?string
    {
        return $moment ? Carbon::parse($moment)->utc()->toIso8601ZuluString() : null;
    }

    private static function safely(callable $work): void
    {
        DB::afterCommit(function () use ($work) {
            try {
                $work(app(self::class));
            } catch (\Throwable $e) {
                Log::warning('Lead webhook could not be emitted', ['error' => $e->getMessage()]);
            }
        });
    }
}
