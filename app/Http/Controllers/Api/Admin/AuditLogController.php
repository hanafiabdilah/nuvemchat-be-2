<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use AdminListing;

    /**
     * Paginated Back Office audit trail.
     *
     * ?search= (description, actor, action), ?action= (any of, "a,b"),
     * ?actor= (actor name, exact), ?from/?to (Y-m-d), ?dir=asc|desc, ?per_page=.
     */
    public function index(Request $request)
    {
        $request->merge($this->listsFrom($request, ['action']));

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'action' => ['sometimes', 'array'],
            'action.*' => ['string', 'max:120'],
            'actor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $logs = AuditLog::query()
            ->when($validated['action'] ?? [], fn ($q, $actions) => $q->whereIn('action', $actions))
            ->when($validated['actor'] ?? null, fn ($q, $actor) => $q->where('actor_name', $actor))
            ->when(($validated['from'] ?? null) || ($validated['to'] ?? null), fn ($q) => $this->whereDayRange(
                $q, 'created_at', $validated['from'] ?? null, $validated['to'] ?? null,
            ))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")
                ->orWhere('actor_name', 'like', "%{$search}%")
                ->orWhere('action', 'like', "%{$search}%")))
            ->orderBy('id', $this->direction($request))
            ->paginate($validated['per_page'] ?? 30)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    /**
     * What the filters pick from: every action and every actor that has ever
     * been written, not a list kept in the front end that falls behind each
     * time something new starts being audited.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'data' => [
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->values(),
                'actors' => AuditLog::query()->whereNotNull('actor_name')->distinct()->orderBy('actor_name')->pluck('actor_name')->values(),
            ],
        ]);
    }
}
