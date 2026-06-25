<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Paginated list of tenant users across every customer (platform-wide).
     *
     * Only users that belong to a tenant are returned — platform admins
     * (tenant_id = null) are excluded. Supports ?search=, ?tenant_id=,
     * ?per_page= and ?sort= (newest|oldest).
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'newest') === 'oldest' ? 'asc' : 'desc';

        $users = User::query()
            ->whereNotNull('tenant_id')
            ->with(['roles', 'tenant.user'])
            ->when($request->filled('tenant_id'), fn ($q) =>
                $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($search !== '', fn ($q) =>
                $q->where(fn ($w) =>
                    $w->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('id', $sort)
            ->paginate($perPage)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }
}
