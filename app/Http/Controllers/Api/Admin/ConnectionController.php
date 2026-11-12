<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ConnectionResource;
use App\Models\Connection;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    use AdminListing;

    /**
     * Paginated list of channel connections across every customer.
     *
     * ?search= (connection name, or the customer's owner name/e-mail),
     * ?channel= and ?status= (any of, "a,b"), ?tenant_id=, ?per_page=,
     * ?sort=created_at|name|conversations|status|channel (+ dir) — newest|oldest
     * still work.
     */
    public function index(Request $request)
    {
        $request->merge($this->listsFrom($request, ['channel', 'status']));

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'channel' => ['sometimes', 'array'],
            'channel.*' => ['string', 'max:40'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'max:40'],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        [$sort, $dir] = $this->sortOf($request, ['created_at', 'name', 'conversations', 'status', 'channel']);

        $connections = Connection::query()
            ->with('tenant.user')
            ->withCount('conversations')
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['channel'] ?? [], fn ($q, $values) => $q->whereIn('channel', $values))
            ->when($validated['status'] ?? [], fn ($q, $values) => $q->whereIn('status', $values))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('tenant.user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->orderBy(match ($sort) {
                'created_at' => 'id',
                'conversations' => 'conversations_count',
                default => $sort,
            }, $dir)
            ->orderBy('id', $dir)
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return ConnectionResource::collection($connections);
    }
}
