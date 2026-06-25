<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ConnectionResource;
use App\Models\Connection;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    /**
     * Paginated list of channel connections across every customer.
     *
     * Filters: ?search= (name), ?channel=, ?status=, ?tenant_id=,
     * ?per_page=, ?sort= (newest|oldest).
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'newest') === 'oldest' ? 'asc' : 'desc';

        $connections = Connection::query()
            ->with('tenant.user')
            ->withCount('conversations')
            ->when($request->filled('tenant_id'), fn ($q) =>
                $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('channel'), fn ($q) =>
                $q->where('channel', $request->query('channel')))
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->query('status')))
            ->when($search !== '', fn ($q) =>
                $q->where('name', 'like', "%{$search}%"))
            ->orderBy('id', $sort)
            ->paginate($perPage)
            ->withQueryString();

        return ConnectionResource::collection($connections);
    }
}
