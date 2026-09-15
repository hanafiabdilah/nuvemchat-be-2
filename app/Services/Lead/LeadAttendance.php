<?php

namespace App\Services\Lead;

use App\Enums\Broadcast\Source as BroadcastSource;
use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\LeadUpdated;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadPipeline;
use App\Models\LeadStage;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a card out of the first column the moment someone from the team
 * answers the contact.
 *
 * Every conversation opens a card in "Novo contato", and nothing used to take
 * it out again short of a drag — so that column ended up holding every contact
 * who ever wrote, answered or not, and could no longer say who is still waiting
 * for a first reply.
 *
 * "Answered" means one thing, the same one the statistics call a first human
 * reply: an outgoing message with a person behind it (`sent_by_user_id`). A
 * flow or AI reply is not a person answering; a campaign is a person
 * broadcasting, not answering — `withoutAdvancing()` keeps it out.
 *
 * Only ever forward: a card already past the target stage stays where it is, so
 * a customer writing again never drags a negotiation back to the start.
 */
final class LeadAttendance
{
    private static bool $suppressed = false;

    /**
     * Run a send that must not count as answering (a campaign reaching a list).
     * Restores the previous flag rather than resetting it — a queue worker runs
     * thousands of jobs in one process.
     */
    public static function withoutAdvancing(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * Entry point for MessageLeadObserver: a message just became a person's.
     */
    public function noteHumanReply(Message $message): void
    {
        if (self::$suppressed
            || $message->sent_by_user_id === null
            || $message->sender_type !== SenderType::Outgoing
            || $message->message_type === MessageType::Info) {
            return;
        }

        $leadId = Conversation::whereKey($message->conversation_id)->value('lead_id');

        // No card yet: EnsureLeadForConversation has not run. It checks for a
        // reply itself once it opens one, so nothing is lost by returning.
        $lead = $leadId ? Lead::find($leadId) : null;

        if (! $lead || ! $this->advance($lead)) {
            return;
        }

        try {
            broadcast(new LeadUpdated($lead));
        } catch (\Throwable $e) {
            Log::warning('Lead moved to the attended stage but could not be broadcast', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** For a card opened after the thread was already answered. */
    public function advanceIfAttended(Lead $lead, Conversation $conversation): bool
    {
        $answered = self::humanReplies(Message::query()->toBase())
            ->where('messages.conversation_id', $conversation->id)
            ->exists();

        return $answered && $this->advance($lead);
    }

    /**
     * Move the card to the workspace's attended stage, if it is still before
     * it. Returns whether it moved.
     */
    public function advance(Lead $lead): bool
    {
        // Re-read: a card fresh out of Lead::create() carries only the columns
        // it was given (status comes from the column default), and a stale
        // instance may be sitting in a stage someone has since dragged it out of.
        $lead->refresh();

        if ($lead->status !== LeadStatus::Open) {
            return false;
        }

        $tenant = Tenant::find($lead->tenant_id);
        $target = $tenant ? $this->targetStage($tenant) : null;

        // A card is only ever moved inside the funnel it lives in.
        if (! $target || $target->pipeline_id !== $lead->pipeline_id || $target->id === $lead->stage_id) {
            return false;
        }

        $current = LeadStage::find($lead->stage_id);

        if (! $current || $current->kind !== StageKind::Open || $current->position >= $target->position) {
            return false;
        }

        // No actor: the history then reads "automático", which is what makes
        // the move explainable to whoever wonders why the card went there.
        $lead->moveToStage($target);

        return true;
    }

    /** The stage the workspace's rule points at, if it still exists and can take cards. */
    public function targetStage(Tenant $tenant): ?LeadStage
    {
        $id = LeadSettings::for($tenant)->attendedStageId;

        return $id ? $this->selectableStage($tenant, $id) : null;
    }

    /**
     * A stage the rule may point at: open, in this workspace, and not the first
     * open stage of its funnel — nothing sits before that one to move.
     */
    public function selectableStage(Tenant $tenant, int $stageId): ?LeadStage
    {
        $stage = LeadStage::whereKey($stageId)
            ->where('kind', StageKind::Open)
            ->whereHas('pipeline', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->first();

        if (! $stage) {
            return null;
        }

        $firstId = LeadStage::where('pipeline_id', $stage->pipeline_id)
            ->where('kind', StageKind::Open)
            ->orderBy('position')
            ->value('id');

        return $firstId === $stage->id ? null : $stage;
    }

    /**
     * What the settings dialog offers, in board order.
     *
     * @return list<array{id: int, name: string, pipeline: string}>
     */
    public function stageOptions(Tenant $tenant): array
    {
        return LeadPipeline::where('tenant_id', $tenant->id)
            ->with('stages')
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->get()
            ->flatMap(fn (LeadPipeline $pipeline) => $pipeline->stages
                ->filter(fn (LeadStage $stage) => $stage->kind === StageKind::Open)
                ->sortBy('position')
                ->values()
                ->slice(1)
                ->map(fn (LeadStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'pipeline' => $pipeline->name,
                ]))
            ->values()
            ->all();
    }

    /**
     * Narrow a query on `messages` to a person answering: outgoing, written by a
     * user, not a platform note, and not a campaign send (a campaign message
     * carries its author in `sent_by_user_id` too). Sends from the inbox's
     * "send message" bar do count — an agent picked those threads and wrote.
     */
    public static function humanReplies(QueryBuilder $messages): QueryBuilder
    {
        return $messages
            ->where('messages.sender_type', SenderType::Outgoing->value)
            ->whereNotNull('messages.sent_by_user_id')
            ->where('messages.message_type', '!=', MessageType::Info->value)
            ->whereNotExists(function (QueryBuilder $campaign) {
                $campaign->select(DB::raw(1))
                    ->from('broadcast_recipients')
                    ->join('broadcasts', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
                    ->whereColumn('broadcast_recipients.message_id', 'messages.id')
                    ->where('broadcasts.source', '!=', BroadcastSource::Inbox->value);
            });
    }

    /** Leads with at least one answered conversation. */
    public static function whereAnswered(Builder $leads): Builder
    {
        return $leads->whereExists(function (QueryBuilder $messages) {
            self::humanReplies(
                $messages->select(DB::raw(1))
                    ->from('messages')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->whereColumn('conversations.lead_id', 'leads.id')
            );
        });
    }
}
