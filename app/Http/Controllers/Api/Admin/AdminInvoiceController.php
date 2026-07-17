<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminInvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminInvoiceController extends Controller
{
    /**
     * Platform-wide invoice list.
     *
     * Backs both Back Office pages: Invoices (every status) and Payments
     * (`?status=paid&sort=paid_at`). There is no separate payments table — an
     * invoice IS the charge record, so Payments is just this list through a
     * narrower lens.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        // Payments are browsed by when the money landed; invoices by when raised.
        $sortByPaidAt = $request->query('sort') === 'paid_at';
        $dateColumn = $sortByPaidAt ? 'paid_at' : 'created_at';

        $invoices = Invoice::query()
            ->with(['tenant.user', 'subscription.plan'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->query('payment_method')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->query('search');
                $q->whereHas('tenant.user', fn ($u) => $u
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($request->filled('from'), fn ($q) => $q->where(
                $dateColumn, '>=', Carbon::parse($request->query('from'))->startOfDay()
            ))
            ->when($request->filled('to'), fn ($q) => $q->where(
                $dateColumn, '<=', Carbon::parse($request->query('to'))->endOfDay()
            ))
            ->when($sortByPaidAt, fn ($q) => $q->orderByDesc('paid_at'))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return AdminInvoiceResource::collection($invoices);
    }
}
