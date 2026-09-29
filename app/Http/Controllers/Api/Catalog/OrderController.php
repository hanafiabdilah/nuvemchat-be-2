<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Enums\Catalog\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What the AI sold. Read-only: orders are written by the AI and the gateway,
 * never by a person — there is nothing here a person could change that would
 * still be true of the money.
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()))],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::where('tenant_id', (int) $request->user()->tenant_id)
            // An empty cart is not an order anybody placed.
            ->where(fn ($q) => $q->where('status', '!=', OrderStatus::Open->value)->orWhere('total_cents', '>', 0))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->when(ctype_digit(ltrim($search, '#')), fn ($x) => $x->orWhere('id', (int) ltrim($search, '#')))
                    ->orWhereHas('contact', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('items', fn ($i) => $i->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->with(['contact', 'latestPayment'])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $id)
    {
        $order = Order::where('tenant_id', (int) $request->user()->tenant_id)
            ->with(['contact', 'items', 'latestPayment'])
            ->findOrFail($id);

        return new OrderResource($order);
    }
}
