<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;

class StatsController extends Controller
{
    /**
     * Platform-wide aggregate counts for the Back Office dashboard.
     */
    public function index()
    {
        return response()->json([
            'data' => [
                'customers' => Tenant::count(),
                'users' => User::whereNotNull('tenant_id')->count(),
                'connections' => Connection::count(),
                'contacts' => Contact::count(),
                'conversations' => Conversation::count(),
            ],
        ]);
    }
}
