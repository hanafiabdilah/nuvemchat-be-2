<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageLog;
use Illuminate\Http\Request;

/**
 * Back Office: read-only feed of every WhatsApp message the platform has sent
 * (OTP codes + lifecycle notifications), with their delivery status.
 */
class WhatsappLogController extends Controller
{
    use AdminListing;

    public function index(Request $request)
    {
        // Capped: an uncapped per_page let one request read the whole table.
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $dates = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $from = $dates['from'] ?? null;
        $to = $dates['to'] ?? null;
        $status = $request->query('status');   // sent | failed
        $type = $request->query('type');       // otp | notification:*
        $search = $request->query('search');   // recipient contains

        $logs = WhatsappMessageLog::query()
            ->with('user:id,name,email')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', 'like', "{$type}%"))
            // The number, or the person it was sent to.
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('recipient', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))))
            ->when($from || $to, fn ($q) => $this->whereDayRange($q, 'created_at', $from, $to))
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
