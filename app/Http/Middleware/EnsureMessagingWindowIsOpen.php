<?php

namespace App\Http\Middleware;

use App\Enums\Connection\Channel;
use App\Models\Conversation;
use App\Services\Messaging\ExpiredWindowResolver;
use App\Services\Messaging\MessagingWindow;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a send once the channel's session window has closed, before anything
 * is written or dispatched.
 *
 * The failure it replaces is a quiet one: WhatsApp's Cloud API often answers a
 * late message with HTTP 200, so the handler stored a Message row and the
 * thread showed a bubble that had already been rejected — Meta only says so
 * later, in a `failed` status webhook nobody surfaces. Better a clear 422 the
 * agent can act on.
 *
 * Applied to the conversation send-* routes; channels with no window (API Way,
 * Telegram, e-mail, …) pass straight through.
 */
class EnsureMessagingWindowIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $conversation = $this->resolveConversation($request);

        if (! $conversation || MessagingWindow::isOpen($conversation)) {
            return $next($request);
        }

        // A window that ran out ends the conversation; one that never opened
        // (a template still awaiting its first reply) leaves it live.
        ExpiredWindowResolver::resolve($conversation);

        $channel = $conversation->connection->channel;
        $hours = MessagingWindow::hoursFor($channel);

        return response()->json([
            'message' => $this->explain($channel, $hours),
            // Machine-readable so the SPA can react without matching on prose.
            'code' => 'messaging_window_closed',
            'window' => [
                'channel' => $channel,
                'hours' => $hours,
                'closed_at' => MessagingWindow::closesAt($conversation)?->toIso8601String(),
            ],
        ], 422);
    }

    /**
     * The conversation this send targets, or null when the guard should stand
     * aside.
     *
     * Anything the controller would refuse anyway — another tenant's thread, a
     * thread this agent does not hold — is left to the controller: its 404/403
     * is the right answer, and no conversation should be auto-resolved off the
     * back of a request that was never allowed to send.
     */
    private function resolveConversation(Request $request): ?Conversation
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $conversation = Conversation::with('connection')->find($request->route('id'));

        if (! $conversation || $conversation->connection?->tenant_id !== $user->tenant_id) {
            return null;
        }

        return $conversation->isAccessibleBy($user) ? $conversation : null;
    }

    private function explain(Channel $channel, ?int $hours): string
    {
        if ($channel === Channel::WhatsappOfficial) {
            return "WhatsApp only allows free messages within {$hours} hours of the customer's last message. "
                . 'That window has closed — send an approved template to reach this contact again.';
        }

        return "This channel only allows replies within {$hours} hours of the customer's last message. "
            . 'That window has closed — you can reply again once they write.';
    }
}
