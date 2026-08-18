<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Broadcast\Status;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Http\Request;

/**
 * Back Office: every campaign on the platform, and the ability to stop one.
 *
 * Campaigns are the only tenant action that spends the platform's reputation
 * rather than the tenant's own: a bad blast gets the *number* banned, and the
 * bill for WhatsApp template sends arrives here. Until now none of it was
 * visible — and when the `queue-broadcast` worker dies, the first anyone hears
 * is a customer asking why their campaign stopped halfway.
 *
 * Pause and cancel go through the same BroadcastService the tenant uses, so an
 * intervention leaves the campaign in a state the tenant's own UI understands
 * and can resume from.
 */
class AdminBroadcastController extends Controller
{
    /**
     * A running campaign whose pump has not checked in for this long has lost
     * its worker. Matches the watchdog's own threshold in `broadcasts:tick`.
     */
    private const STALE_TICK_MINUTES = 2;

    public function __construct(
        private BroadcastService $broadcasts,
    ) {}

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $campaigns = Broadcast::query()
            ->with(['tenant.user:id,name,email', 'connection:id,name,channel', 'creator:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->integer('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($request->boolean('stalled'), fn ($q) => $this->applyStalled($q))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $campaigns->getCollection()->map(fn (Broadcast $b) => $this->present($b)),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
                'from' => $campaigns->firstItem(),
                'to' => $campaigns->lastItem(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Stop the pump but keep the campaign resumable — the right first move when
     * something looks wrong, because it is reversible by the tenant.
     */
    public function pause(Broadcast $broadcast)
    {
        if (! $broadcast->status->isActive()) {
            return response()->json(['message' => 'Only a running campaign can be paused.'], 422);
        }

        return response()->json(['data' => $this->present($this->broadcasts->pause($broadcast)->fresh())]);
    }

    /** Ends the campaign for good. Unsent recipients are never sent. */
    public function cancel(Broadcast $broadcast)
    {
        if ($broadcast->status->isFinished()) {
            return response()->json(['message' => 'Campaign is already finished.'], 422);
        }

        return response()->json(['data' => $this->present($this->broadcasts->cancel($broadcast)->fresh())]);
    }

    /**
     * Counts the page leads with. `stalled` is the one that matters: it is the
     * only number here that means "something is broken right now".
     */
    private function summary(): array
    {
        $byStatus = Broadcast::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'running' => (int) ($byStatus[Status::Running->value] ?? 0),
            'scheduled' => (int) ($byStatus[Status::Scheduled->value] ?? 0),
            'paused' => (int) ($byStatus[Status::Paused->value] ?? 0),
            'failed' => (int) ($byStatus[Status::Failed->value] ?? 0),
            'stalled' => $this->applyStalled(Broadcast::query())->count(),
            'sent_24h' => (int) Broadcast::query()
                ->where('updated_at', '>=', now()->subDay())
                ->sum('sent_count'),
        ];
    }

    /** Running, but the pump stopped re-dispatching itself. */
    private function applyStalled($query)
    {
        return $query
            ->where('status', Status::Running->value)
            ->where(fn ($q) => $q
                ->whereNull('last_tick_at')
                ->orWhere('last_tick_at', '<', now()->subMinutes(self::STALE_TICK_MINUTES)));
    }

    private function present(Broadcast $b): array
    {
        $tickAge = $b->last_tick_at?->diffInSeconds(now());

        return [
            'id' => $b->id,
            'name' => $b->name,
            'status' => $b->status->value,
            'content_type' => $b->content_type->value,
            'tenant' => [
                'id' => $b->tenant_id,
                'name' => $b->tenant?->user?->name ?? "Tenant #{$b->tenant_id}",
                'email' => $b->tenant?->user?->email,
            ],
            'connection' => $b->getRelationValue('connection') ? [
                'id' => $b->getRelationValue('connection')->id,
                'name' => $b->getRelationValue('connection')->name,
                'channel' => $b->getRelationValue('connection')->channel instanceof \BackedEnum
                    ? $b->getRelationValue('connection')->channel->value
                    : $b->getRelationValue('connection')->channel,
            ] : null,
            'created_by' => $b->creator?->name,
            'rate_per_minute' => $b->rate_per_minute,
            'total_recipients' => $b->total_recipients,
            'sent_count' => $b->sent_count,
            'failed_count' => $b->failed_count,
            'skipped_count' => $b->skipped_count,
            'progress_pct' => $b->total_recipients > 0
                ? round((($b->sent_count + $b->failed_count + $b->skipped_count) / $b->total_recipients) * 100, 1)
                : 0.0,
            // Surfaced per row and not only in the summary: the operator needs
            // to know which campaign is the dead one, not just that one exists.
            'is_stalled' => $b->status === Status::Running
                && ($b->last_tick_at === null || $tickAge > self::STALE_TICK_MINUTES * 60),
            'last_tick_age_seconds' => $tickAge === null ? null : (int) $tickAge,
            'error' => $b->error,
            'scheduled_at' => $b->scheduled_at?->toIso8601String(),
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
