<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Paginated Back Office audit trail. Filters: ?search=, ?action=, ?per_page=.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 30), 100));
        $search = trim((string) $request->query('search', ''));

        $logs = AuditLog::query()
            ->when($request->filled('action'), fn ($q) =>
                $q->where('action', $request->query('action')))
            ->when($search !== '', fn ($q) =>
                $q->where(fn ($w) =>
                    $w->where('description', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
