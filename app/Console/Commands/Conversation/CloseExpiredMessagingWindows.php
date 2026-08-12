<?php

namespace App\Console\Commands\Conversation;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Services\Messaging\ExpiredWindowResolver;
use App\Services\Messaging\MessagingWindow;
use Illuminate\Console\Command;

/**
 * Closes conversations nobody can answer any more.
 *
 * Without this an expired WhatsApp Official thread sits in the Active column
 * forever, looking like work an agent could still pick up. The send guard
 * catches the same state, but only once someone tries to type — this makes the
 * inbox tell the truth on its own.
 */
class CloseExpiredMessagingWindows extends Command
{
    protected $signature = 'conversations:close-expired-windows
                            {--limit=200 : Conversations to close per channel in one pass}
                            {--dry-run : List what would close without touching anything}';

    protected $description = 'Resolve active conversations whose channel reply window has expired, leaving an info note in the thread';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        foreach (Channel::cases() as $channel) {
            $hours = MessagingWindow::hoursFor($channel);

            if ($hours === null) {
                continue;
            }

            $candidates = Conversation::with('connection')
                ->where('status', Status::Active)
                ->whereHas('connection', fn ($q) => $q->where('channel', $channel))
                // The window is measured from the customer's last message, so a
                // recent reply of ours proves nothing — ask about inbound only.
                // Threads still waiting for their first inbound (started by a
                // template) are excluded: their window has not opened yet, so
                // it cannot have expired.
                ->whereHas('messages', fn ($q) => $q->where('sender_type', SenderType::Incoming))
                ->whereDoesntHave('messages', fn ($q) => $q
                    ->where('sender_type', SenderType::Incoming)
                    ->where('sent_at', '>=', now()->subHours($hours)))
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($candidates as $conversation) {
                if ($dryRun) {
                    $this->line(sprintf(
                        '  would close #%d (%s, last inbound %s)',
                        $conversation->id,
                        $channel->value,
                        MessagingWindow::lastInboundAt($conversation)?->diffForHumans() ?? 'never',
                    ));
                    $closed++;
                    continue;
                }

                // resolve() re-checks the window itself, so a row the coarse
                // query picked up by accident is left alone.
                if (ExpiredWindowResolver::resolve($conversation)) {
                    $closed++;
                }
            }

            if ($candidates->count() === $limit) {
                $this->warn("{$channel->value}: hit the {$limit}-per-pass limit — more conversations are still open, rerun to continue.");
            }
        }

        $this->info($dryRun
            ? "{$closed} conversation(s) would be resolved."
            : "{$closed} conversation(s) resolved.");

        return self::SUCCESS;
    }
}
