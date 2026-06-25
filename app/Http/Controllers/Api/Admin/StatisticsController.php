<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GrowthStats;

class StatisticsController extends Controller
{
    /**
     * Platform analytics: totals, 12-month cumulative growth per entity,
     * and channel/status breakdowns.
     */
    public function index()
    {
        $channels = Connection::selectRaw('channel, COUNT(*) as c')
            ->groupBy('channel')->get()
            ->map(fn ($r) => [
                'channel' => $r->channel instanceof Channel ? $r->channel->value : $r->channel,
                'count' => (int) $r->c,
            ])->values();

        $statuses = Connection::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')->get()
            ->map(fn ($r) => [
                'status' => $r->status instanceof Status ? $r->status->value : $r->status,
                'count' => (int) $r->c,
            ])->values();

        return response()->json([
            'data' => [
                'totals' => [
                    'customers' => Tenant::count(),
                    'users' => User::whereNotNull('tenant_id')->count(),
                    'connections' => Connection::count(),
                    'conversations' => Conversation::count(),
                    'contacts' => Contact::count(),
                ],
                'growth' => [
                    'customers' => GrowthStats::cumulative(fn () => Tenant::query()),
                    'users' => GrowthStats::cumulative(fn () => User::whereNotNull('tenant_id')),
                    'connections' => GrowthStats::cumulative(fn () => Connection::query()),
                    'conversations' => GrowthStats::cumulative(fn () => Conversation::query()),
                ],
                'channels' => $channels,
                'statuses' => $statuses,
            ],
        ]);
    }
}
