<?php

namespace App\Services\Statistics;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\SenderType;
use Illuminate\Support\Facades\DB;

/**
 * Whether the plumbing held: messages the channel refused, how much of what we
 * sent was confirmed delivered or read, the state of each connection, and
 * media that never arrived.
 *
 * `messages.error` has been filled in by every send handler since the first
 * channel shipped and has only ever been visible one bubble at a time. A
 * tenant whose token expired at noon can see it here as a wall of failures
 * against one connection instead of discovering it from a customer.
 */
class HealthStats
{
    public function __construct(private readonly StatsScope $scope) {}

    public function build(): array
    {
        return [
            'failures' => $this->failures(),
            'receipts' => $this->receipts(),
            'connections' => $this->connections(),
            'media' => $this->media(),
        ];
    }

    private function failures(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->whereNotNull('messages.error')
            ->groupBy('connections.id', 'connections.name', 'connections.channel')
            ->select(
                'connections.id as connection_id',
                'connections.name',
                'connections.channel',
                DB::raw('COUNT(*) as total'),
                DB::raw('MAX(messages.created_at) as last_at')
            )
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $sent = $this->scope->messages($this->scope->from, $this->scope->to)
            ->where('messages.sender_type', SenderType::Outgoing->value)
            ->count();

        // One example per connection, so the number comes with a cause.
        $samples = [];
        foreach ($rows as $row) {
            $samples[$row->connection_id] = $this->scope->messages($this->scope->from, $this->scope->to)
                ->whereNotNull('messages.error')
                ->where('conversations.connection_id', $row->connection_id)
                ->orderByDesc('messages.created_at')
                ->value('messages.error');
        }

        $total = (int) $rows->sum('total');

        return [
            'total' => $total,
            'outgoing_total' => $sent,
            'rate' => $sent > 0 ? round(($total / $sent) * 100, 1) : 0.0,
            'items' => $rows->map(fn ($row) => [
                'connection_id' => (int) $row->connection_id,
                'name' => $row->name,
                'channel' => $row->channel,
                'total' => (int) $row->total,
                'last_at' => $row->last_at,
                'sample_error' => $samples[$row->connection_id] ?? null,
            ])->all(),
        ];
    }

    /**
     * Delivery and read confirmations per channel.
     *
     * Reported per channel and never summed into one headline rate: only some
     * channels send receipts at all, so a tenant-wide "48% read" would mostly
     * measure which channels they use. The client labels each row accordingly.
     */
    private function receipts(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->where('messages.sender_type', SenderType::Outgoing->value)
            ->groupBy('connections.channel')
            ->select(
                'connections.channel',
                DB::raw('COUNT(*) as sent'),
                DB::raw('SUM(CASE WHEN messages.delivery_at IS NOT NULL THEN 1 ELSE 0 END) as delivered'),
                DB::raw('SUM(CASE WHEN messages.read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count')
            )
            ->orderByDesc('sent')
            ->get();

        return $rows->map(function ($row) {
            $sent = (int) $row->sent;

            return [
                'channel' => $row->channel,
                'sent' => $sent,
                'delivered' => (int) $row->delivered,
                'read' => (int) $row->read_count,
                'delivery_rate' => $sent > 0 ? round(((int) $row->delivered / $sent) * 100, 1) : 0.0,
                'read_rate' => $sent > 0 ? round(((int) $row->read_count / $sent) * 100, 1) : 0.0,
            ];
        })->all();
    }

    private function connections(): array
    {
        $rows = DB::table('connections')
            ->where('tenant_id', $this->scope->tenantId)
            ->select('id', 'name', 'channel', 'status', 'color', 'sync_status', 'sync_error', 'last_synced_at')
            ->orderBy('name')
            ->get();

        return [
            'total' => $rows->count(),
            'inactive' => $rows->where('status', '!=', ConnectionStatus::Active->value)->count(),
            'items' => $rows->map(fn ($row) => [
                'connection_id' => (int) $row->id,
                'name' => $row->name,
                'channel' => $row->channel,
                'status' => $row->status,
                'color' => $row->color,
                'sync_status' => $row->sync_status,
                'sync_error' => $row->sync_error,
                'last_synced_at' => $row->last_synced_at,
            ])->all(),
        ];
    }

    /**
     * Attachments still travelling, given up on, or purged by retention. Null
     * is the resting state and is not counted — it means "no media, or media
     * already on disk".
     */
    private function media(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->whereNotNull('messages.attachment_status')
            ->groupBy('messages.attachment_status')
            ->select('messages.attachment_status as status', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($rows[AttachmentStatus::Pending->value] ?? 0),
            'failed' => (int) ($rows[AttachmentStatus::Failed->value] ?? 0),
            'expired' => (int) ($rows[AttachmentStatus::Expired->value] ?? 0),
        ];
    }
}
