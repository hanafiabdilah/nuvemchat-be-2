<?php

namespace App\Services\User;

use App\Events\AccountDeactivated;
use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Someone who no longer works here: their access ends, their history stays.
 *
 * The gentler sibling of AgentRemoval. Deleting a person takes their name off
 * everything they did — resolved conversations lose their assignee and the
 * statistics stop crediting them — so a company that only wanted to close a
 * former employee's account had to choose between keeping a door open and
 * erasing a year of work. Deactivating keeps the row, the roles, the inboxes
 * they had, and their name on every conversation they handled, and ends
 * everything else:
 *
 *  - every session and MCP connection is revoked now, and signing in is
 *    refused (AuthController) — including a password reset, which behaves as
 *    for an address with no account;
 *  - they reach no connection (User::accessibleConnectionIds), so nothing is
 *    routed, transferred or assigned to them, and no alert is sent to them;
 *  - they stop counting as a seat, so the plan's agent quota frees up;
 *  - their open conversations move now, exactly as on deletion
 *    (OpenConversationHandover) — the customers are mid-conversation.
 *
 * Reactivating gives all of it back, as long as the plan has a free seat.
 */
class AgentDeactivation
{
    public function __construct(private readonly OpenConversationHandover $handover) {}

    /**
     * @return array{changed: bool, transferred: int, returned_to_queue: int}
     */
    public function deactivate(User $agent, User $actor, ?User $reassignTo = null): array
    {
        $summary = ['changed' => false, 'transferred' => 0, 'returned_to_queue' => 0];
        $moved = [];

        DB::transaction(function () use ($agent, $actor, $reassignTo, &$summary, &$moved) {
            // Two people pressing the button at once must not both move the
            // conversations: the second waits here and finds it already done.
            $locked = User::query()->whereKey($agent->id)->lockForUpdate()->first();

            if (! $locked || $locked->isDeactivated()) {
                return;
            }

            $handed = $this->handover->handOver($agent, $actor, $reassignTo, 'agent_deactivated');
            $summary['transferred'] = $handed['transferred'];
            $summary['returned_to_queue'] = $handed['returned_to_queue'];
            $moved = $handed['ids'];

            $agent->forceFill([
                'deactivated_at' => now(),
                'deactivated_by_user_id' => $actor->id,
                // Offline too, so every presence reader agrees even before it
                // learns to ask about deactivation. Who switched it off is left
                // empty: the reason is the deactivation, not a supervisor's
                // call about today.
                'offline_since' => $agent->offline_since ?? now(),
                'offline_set_by_user_id' => null,
            ])->save();

            // Access ends now, not when their token next expires. The phone's
            // push registration hangs off the session (cascade), so this also
            // stops the next customer being announced on their phone.
            $agent->tokens()->delete();

            McpConnection::query()
                ->where('user_id', $agent->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $summary['changed'] = true;
        });

        if (! $summary['changed']) {
            return $summary;
        }

        $this->handover->broadcast($moved);

        try {
            broadcast(new AccountDeactivated($agent->id));
        } catch (\Throwable $e) {
            // Their tokens are gone either way; the open tab just finds out on
            // its next request instead of now.
            Log::warning('AgentDeactivation: could not announce the deactivation', [
                'user_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->audit('agent.deactivated', $agent, [
            'transferred' => $summary['transferred'],
            'returned_to_queue' => $summary['returned_to_queue'],
            'reassigned_to' => $reassignTo?->id,
        ]);

        return $summary;
    }

    /**
     * Give the account back. Returns whether anything changed. The caller
     * checks the seat quota first — a reactivated person is a seat again.
     */
    public function reactivate(User $agent, User $actor): bool
    {
        $changed = DB::transaction(function () use ($agent) {
            $locked = User::query()->whereKey($agent->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isDeactivated()) {
                return false;
            }

            // Still Offline: they come online by signing in, which is the one
            // moment the switch turns back on (AuthController::grant).
            $agent->forceFill([
                'deactivated_at' => null,
                'deactivated_by_user_id' => null,
            ])->save();

            return true;
        });

        if ($changed) {
            $this->audit('agent.reactivated', $agent, []);
        }

        return $changed;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(string $action, User $agent, array $metadata): void
    {
        AuditLog::record(
            $action,
            "Account of {$agent->name} in workspace #{$agent->tenant_id}",
            array_merge([
                'tenant_id' => $agent->tenant_id,
                'user_id' => $agent->id,
                'user' => $agent->name,
            ], $metadata),
        );
    }
}
