<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Events\UserPresenceUpdated;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The agent control page: what the person running the shift does to the team
 * during the day.
 *
 * One action for now — turning somebody's switch to Offline — and only that
 * way round. The switch (User::offline_since) belongs to the agent: Offline
 * takes them out of everything that routes work to a person automatically
 * (returning customers, the flow's assign-to-agent action, "an agent is
 * online" handoff), and only the agent can say they are back, from their own
 * menu or by signing in again. A supervisor who could also turn it on would be
 * declaring somebody present who may not be at the desk, and customers would
 * queue for an empty chair.
 *
 * Separate from AgentController on purpose: that one is the roster set up
 * once (who is on the team and what they may do), this is the day.
 */
class AgentControlController extends Controller
{
    /** Effective status: the switch and the dashboard's heartbeat, read together. */
    private const STATUS_ORDER = ['online' => 0, 'away' => 1, 'offline' => 2];

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        // The team's day: people who left (deactivated) are not on it.
        $users = $actor->tenant->users()
            ->active()
            ->with(['roles', 'offlineSetBy:id,name'])
            ->get();

        $open = $this->openConversations($users);

        $rows = $users
            ->map(fn (User $user) => $this->row($user, $actor, (int) ($open[$user->id] ?? 0)))
            ->sort(fn (array $a, array $b) => [self::STATUS_ORDER[$a['status']], mb_strtolower((string) $a['name'])]
                <=> [self::STATUS_ORDER[$b['status']], mb_strtolower((string) $b['name'])])
            ->values();

        return response()->json([
            'data' => $rows,
            // How long a heartbeat counts as "at the desk", so the page can say
            // what "Away" means in minutes rather than in a vague word.
            'online_window_seconds' => (int) config('presence.online_seconds', 150),
        ]);
    }

    /**
     * Turn an agent's switch to Offline.
     *
     * Already Offline is not an error: two supervisors pressing it in the same
     * minute both got what they wanted. The answer says so (`changed: false`),
     * and nothing is announced or logged a second time.
     */
    public function setOffline(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if ($this->isImpersonating($request)) {
            // The trail would name the workspace's owner for something a Back
            // Office operator did.
            return response()->json([
                'message' => __('Changing an agent\'s status is not available while viewing as this workspace.'),
                'code' => 'impersonation_read_only',
            ], 403);
        }

        $agent = $actor->tenant->users()->active()->with(['roles'])->findOrFail($id);

        if (! $this->mayTurnOff($actor, $agent)) {
            return response()->json([
                'message' => __('The owner\'s status can only be changed by the owner.'),
                'code' => 'agent_protected',
            ], 403);
        }

        $changed = $agent->setPresence(false, by: $actor);

        if ($changed) {
            // After the write, so the agent's tabs that refetch on the event
            // read the new value.
            broadcast(new UserPresenceUpdated(
                $agent->id,
                $agent->presenceStatus(),
                $agent->id === $actor->id ? null : $actor->name,
            ));

            AuditLog::record(
                'agent.presence_set_offline',
                "{$actor->name} set {$agent->name} offline in workspace #{$agent->tenant_id}",
                [
                    'tenant_id' => $agent->tenant_id,
                    'user_id' => $agent->id,
                    'user' => $agent->name,
                    // Whether they were at the desk when it happened: "went home
                    // with the tab open" and "was working" read the same in the
                    // switch alone.
                    'was_online' => $agent->last_seen_at !== null && $agent->last_seen_at->gte(User::onlineSince()),
                ],
            );
        }

        $agent->load('offlineSetBy:id,name');

        return response()->json([
            'changed' => $changed,
            'data' => $this->row($agent, $actor, (int) ($this->openConversations(collect([$agent]))[$agent->id] ?? 0)),
        ]);
    }

    /**
     * One person as the page draws them.
     *
     * `status` is the switch and the heartbeat together — what routing reads
     * (User::isOnline) plus the case in between: the switch says Online but the
     * dashboard has not reported in, so nothing is routed to them now, yet the
     * moment the tab wakes up it would be. That middle state is the one a
     * supervisor most often wants to close at the end of the day.
     */
    private function row(User $user, User $actor, int $openConversations): array
    {
        $switchOn = $user->offline_since === null;
        $atDesk = $user->last_seen_at !== null && $user->last_seen_at->gte(User::onlineSince());

        $status = match (true) {
            ! $switchOn => 'offline',
            $atDesk => 'online',
            default => 'away',
        };

        $isOwner = $user->roles->contains('name', 'owner');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar_url,
            'roles' => $user->roles->pluck('name')->values(),
            'is_owner' => $isOwner,
            'is_me' => $user->id === $actor->id,
            'status' => $status,
            'last_seen_at' => $user->last_seen_at?->timestamp,
            'offline_since' => $user->offline_since?->timestamp,
            // Null when the agent switched themselves off, and when the person
            // who did it has since been removed (the FK nulls) — then the page
            // cannot name anybody and does not pretend to.
            'offline_set_by' => $user->offlineSetBy ? [
                'id' => $user->offlineSetBy->id,
                'name' => $user->offlineSetBy->name,
            ] : null,
            'open_conversations' => $openConversations,
            'can_set_offline' => $switchOn && $this->mayTurnOff($actor, $user),
        ];
    }

    /**
     * The owner is off-limits to everybody but the owner, as everywhere else
     * on the team pages — the account running the workspace is not something a
     * role inside it gets to switch off.
     */
    private function mayTurnOff(User $actor, User $agent): bool
    {
        if ($agent->id === $actor->id) {
            return true;
        }

        return ! $agent->roles->contains('name', 'owner');
    }

    /**
     * Conversations each person is answering now (Active, assigned to them):
     * what is left in their hands when they go offline. Offline does not take
     * these away — it only stops new ones arriving on their own.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, int>
     */
    private function openConversations(Collection $users): Collection
    {
        return Conversation::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->where('status', Status::Active->value)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'user_id')
            ->map(fn ($count) => (int) $count);
    }

    private function isImpersonating(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->name === 'impersonation';
    }
}
