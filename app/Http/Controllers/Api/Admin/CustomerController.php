<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Tenant;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Paginated list of every customer (tenant) on the platform.
     *
     * Supports ?search= (owner name/email), ?per_page=, and ?sort=
     * (newest|oldest). Not tenant-scoped — this is a platform admin view.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'newest');

        $customers = Tenant::query()
            ->with('user')
            ->withCount(['users', 'connections', 'contacts', 'conversations'])
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    /**
     * Detail for a single customer (tenant).
     */
    public function show(Tenant $tenant)
    {
        $tenant->load('user')
            ->loadCount(['users', 'connections', 'contacts', 'conversations']);

        return new CustomerResource($tenant);
    }
}
