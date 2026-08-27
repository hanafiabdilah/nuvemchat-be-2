<?php

namespace App\Observers;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\Widget\WidgetConversationStatusChanged;
use App\Jobs\EnsureLeadForConversation;
use App\Models\Conversation;
use App\Services\Conversation\SystemMessage;
use App\Services\Flow\FlowExecutor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ConversationObserver
{
    /**
     * Codes for the note a status change writes into the thread. Two, because
     * the sentence has a subject or it does not, and i18next interpolation
     * cannot make a name optional inside one string.
     */
    public const INFO_STATUS_CHANGED = 'conversation_status_changed';

    public const INFO_STATUS_CHANGED_BY = 'conversation_status_changed_by';

    /**
     * Suppresses the automatic status note for the duration of a callback.
     *
     * Not an escape hatch: it exists for the two moments that already write a
     * note saying more than the transition does. Accepting a thread writes
     * "Ana took this conversation", which names the person as well as implying
     * pending → active; a reply window closing writes why it closed. A second
     * note underneath either of them is noise for one click.
     */
    private static bool $suppressed = false;

    public static function withoutStatusNote(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            // Restored rather than set to false: a queue worker runs thousands
            // of jobs in one process, and a flag left on would silently stop
            // recording status changes for the rest of its life.
            self::$suppressed = $previous;
        }
    }

    /**
     * A new thread means a new sale to track — or an existing one to attach to.
     *
     * Hooked here rather than in the nine per-channel webhook handlers because
     * every channel funnels through this one point, so the funnel fills itself
     * for WhatsApp, Instagram, Telegram, Discord and the rest without any of
     * them knowing leads exist. Queued: a webhook must never wait on work the
     * customer is not waiting for.
     */
    public function created(Conversation $conversation): void
    {
        EnsureLeadForConversation::dispatch($conversation->id);
    }

    /**
     * Handle the Conversation "updated" event.
     * Stop flow when conversation status changes from Pending to Active/Resolved (admin handover).
     * Also broadcasts a widget event so embedded SDKs can react to status changes.
     */
    public function updated(Conversation $conversation): void
    {
        // Check if status was changed
        if (! $conversation->wasChanged('status')) {
            return;
        }

        // Get the previous status
        $oldStatus = $conversation->getOriginal('status');
        $newStatus = $conversation->status;

        $this->noteStatusChange($conversation, $oldStatus, $newStatus);

        // If status changed from Pending to Active or Resolved, stop the flow
        if ($oldStatus === Status::Pending && in_array($newStatus, [Status::Active, Status::Resolved])) {
            Log::info('ConversationObserver: Conversation status changed, stopping flow', [
                'conversation_id' => $conversation->id,
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
            ]);

            $flowExecutor = new FlowExecutor;
            $flowExecutor->stopFlow($conversation);
        }

        // Notify the embedded widget SDK when this conversation belongs to a Live Chat Widget channel.
        if ($conversation->connection?->channel === Channel::LiveChatWidget) {
            broadcast(new WidgetConversationStatusChanged($conversation, $oldStatus, $newStatus));
        }
    }

    /**
     * Record a status change in the thread itself.
     *
     * Hooked to the model rather than written at each call site because there
     * is no single place a status changes: an agent accepting or resolving, a
     * bulk update, a reply window closing, the flow engine handing a thread
     * back to the queue — and whatever adds the next one. A note per call site
     * would be complete on the day it was written and quietly incomplete after
     * the next feature.
     *
     * The actor is whoever is signed in, which is nobody for the paths that run
     * from a queue or a webhook. That absence is the honest answer, and it is
     * why there are two codes: "Ana resolved this" and "this was resolved" are
     * different sentences, not one sentence with a blank in it.
     */
    private function noteStatusChange(Conversation $conversation, mixed $oldStatus, mixed $newStatus): void
    {
        if (self::$suppressed) {
            return;
        }

        $from = $oldStatus instanceof Status ? $oldStatus->value : (string) $oldStatus;
        $to = $newStatus instanceof Status ? $newStatus->value : (string) $newStatus;

        if ($from === '' || $from === $to) {
            return;
        }

        $actor = Auth::user()?->name;

        // Raw enum values, not finished words: the reader's language is decided
        // in the browser, and "Pending" baked in here would be frozen into one.
        $params = ['from_status' => $from, 'to_status' => $to];

        SystemMessage::info(
            $conversation,
            $actor
                ? "{$actor} changed the status from {$from} to {$to}."
                : "Status changed from {$from} to {$to}.",
            $actor ? self::INFO_STATUS_CHANGED_BY : self::INFO_STATUS_CHANGED,
            $actor ? [...$params, 'by' => $actor] : $params,
        );
    }
}
