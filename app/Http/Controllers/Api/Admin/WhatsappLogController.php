<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageLog;
use Illuminate\Http\Request;

/**
 * Back Office: read-only feed of every WhatsApp message the platform has sent
 * (OTP codes + lifecycle notifications), with their delivery status.
 */
class WhatsappLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 25);
        $status = $request->query('status');   // sent | failed
        $type = $request->query('type');       // otp | notification:*
        $search = $request->query('search');   // recipient contains

        $logs = WhatsappMessageLog::query()
            ->with('user:id,name,email')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', 'like', "{$type}%"))
            ->when($search, fn ($q) => $q->where('recipient', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (WhatsappMessageLog $log) => [
                'id' => $log->id,
                'provider' => $log->provider,
                'recipient' => $log->recipient,
                'type' => $log->type,
                'body' => $log->body,
                'status' => $log->status,
                'error' => $log->error,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }
}
