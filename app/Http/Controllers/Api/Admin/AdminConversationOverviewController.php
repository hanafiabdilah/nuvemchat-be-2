<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\AdminPeriod;
use Illuminate\Http\Request;

/**
 * Back Office: conversation volume and backlog per customer.
 *
 * Deliberately not a reader for message content. A platform admin browsing
 * other companies' customer conversations is a privacy problem the product
 * does not need to have, and there is already a supported path for the rare
 * case where someone genuinely must look: impersonation, which is consented to
 * by role and written to the audit log. What operations actually needs from
 * here is different anyway — which workspaces are busy, which are stuck, and
 * whose outbound messages are failing.
 *
 * "Unanswered" is the number that earns the page: a conversation waiting on a
 * first human reply for hours is a customer being ignored, and it is invisible
 * from inside a single tenant's dashboard.
 */
class AdminConversationOverviewController extends Controller
{
    private const TOP_N = 25;

    /** A pending conversation older than this is a backlog, not a queue. */
    private const STALE_PENDING_HOURS = 4;

    public function index(Request $request)
    {
        $period = AdminPeriod::fromRequest($request, 7);
        $offset = (int) $request->integer('tz_offset', 0);
        $tenantId = $request->integer('tenant_id') ?: null;

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $period->from->toIso8601String(),
                    'to' => $period->to->toIso8601String(),
                    'days' => $period->days,
                    'granularity' => $period->bucketsByDay() ? 'day' : 'month',
                ],
                'totals' => $this->totals($period, $tenantId),
                'series' => $this->series($period, $offset, $tenantId),
                'by_tenant' => $tenantId ? [] : $this->byTenant($period),
            ],
        ]);
    }

    private function messages(AdminPeriod $period, ?int $tenantId)
    {
        return Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('connections', 'conversations.connection_id', '=', 'connections.id')
            ->when($tenantId, fn ($q) => $q->where('connections.tenant_id', $tenantId))
            ->whereBetween('messages.created_at', [$period->from, $period->to]);
    }

    private function conversations(?int $tenantId)
    {
        return Conversation::query()
            ->join('connections', 'conversations.connection_id', '=', 'connections.id')
            ->when($tenantId, fn ($q) => $q->where('connections.tenant_id', $tenantId));
    }

    private function totals(AdminPeriod $period, ?int $tenantId): array
    {
        $volume = $this->messages($period, $tenantId)
            ->selectRaw(
                "COUNT(*) as total,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as outbound,
                 SUM(CASE WHEN messages.error IS NOT NULL THEN 1 ELSE 0 END) as failed",
                [SenderType::Incoming->value, SenderType::Outgoing->value]
            )->first();

        $byStatus = $this->conversations($tenantId)
            ->selectRaw('conversations.status as status, COUNT(*) as c')
            ->groupBy('conversations.status')
            ->pluck('c', 'status');

        $stalePending = $this->conversations($tenantId)
            ->where('conversations.status', Status::Pending->value)
            ->where('conversations.updated_at', '<', now()->subHours(self::STALE_PENDING_HOURS))
            ->count();

        return [
            'messages' => (int) ($volume->total ?? 0),
            'inbound' => (int) ($volume->inbound ?? 0),
            'outbound' => (int) ($volume->outbound ?? 0),
            // Outbound rows the channel rejected. A reply the agent believes
            // they sent and the customer never received.
            'failed_sends' => (int) ($volume->failed ?? 0),
            'new_conversations' => $this->conversations($tenantId)
                ->whereBetween('conversations.created_at', [$period->from, $period->to])
                ->count(),
            'open' => (int) ($byStatus[Status::Active->value] ?? 0),
            'pending' => (int) ($byStatus[Status::Pending->value] ?? 0),
            'ai_handling' => (int) ($byStatus[Status::AiHandling->value] ?? 0),
            'resolved' => (int) ($byStatus[Status::Resolved->value] ?? 0),
            'stale_pending' => $stalePending,
            'stale_pending_hours' => self::STALE_PENDING_HOURS,
        ];
    }

    private function series(AdminPeriod $period, int $offset, ?int $tenantId): array
    {
        $expr = $period->bucketExpr('messages.created_at', $offset);

        $rows = $this->messages($period, $tenantId)
            ->selectRaw(
                "$expr as bucket,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as outbound",
                [SenderType::Incoming->value, SenderType::Outgoing->value]
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return array_map(fn (string $bucket) => [
            'period' => $bucket,
            'inbound' => (int) ($rows[$bucket]->inbound ?? 0),
            'outbound' => (int) ($rows[$bucket]->outbound ?? 0),
        ], $period->buckets($offset));
    }

    private function byTenant(AdminPeriod $period): array
    {
        $rows = $this->messages($period, null)
            ->selectRaw(
                "connections.tenant_id as tenant_id,
                 COUNT(*) as messages,
                 SUM(CASE WHEN messages.sender_type = ? THEN 1 ELSE 0 END) as inbound,
                 SUM(CASE WHEN messages.error IS NOT NULL THEN 1 ELSE 0 END) as failed",
                [SenderType::Incoming->value]
            )
            ->groupBy('connections.tenant_id')
            ->orderByDesc('messages')
            ->limit(self::TOP_N)
            ->get();

        $tenantIds = $rows->pluck('tenant_id');

        $stale = $this->conversations(null)
            ->whereIn('connections.tenant_id', $tenantIds)
            ->where('conversations.status', Status::Pending->value)
            ->where('conversations.updated_at', '<', now()->subHours(self::STALE_PENDING_HOURS))
            ->selectRaw('connections.tenant_id as tenant_id, COUNT(*) as c')
            ->groupBy('connections.tenant_id')
            ->pluck('c', 'tenant_id');

        $tenants = Tenant::with('user:id,name,email')
            ->whereIn('id', $tenantIds)
            ->get()
            ->keyBy('id');

        return $rows->map(function ($r) use ($tenants, $stale) {
            $tenant = $tenants[$r->tenant_id] ?? null;

            return [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $tenant?->user?->name ?? "Tenant #{$r->tenant_id}",
                'email' => $tenant?->user?->email,
                'messages' => (int) $r->messages,
                'inbound' => (int) $r->inbound,
                'failed_sends' => (int) $r->failed,
                'stale_pending' => (int) ($stale[$r->tenant_id] ?? 0),
            ];
        })->values()->all();
    }
}
