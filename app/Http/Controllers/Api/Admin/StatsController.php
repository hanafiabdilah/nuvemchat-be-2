<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GrowthStats;

class StatsController extends Controller
{
    /**
     * Platform-wide totals + 12-month cumulative growth for the dashboard.
     * Available to any Back Office admin (the dashboard is the landing page).
     */
    public function index()
    {
        return response()->json([
            'data' => [
                'customers' => Tenant::count(),
                'users' => User::whereNotNull('tenant_id')->count(),
                'connections' => Connection::count(),
                'conversations' => Conversation::count(),
                'contacts' => Contact::count(),
                'growth' => [
                    'customers' => GrowthStats::cumulative(fn () => Tenant::query()),
                    'users' => GrowthStats::cumulative(fn () => User::whereNotNull('tenant_id')),
                    'connections' => GrowthStats::cumulative(fn () => Connection::query()),
                    'conversations' => GrowthStats::cumulative(fn () => Conversation::query()),
                ],
            ],
        ]);
    }
}
