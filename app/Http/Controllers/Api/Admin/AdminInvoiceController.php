<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminInvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminInvoiceController extends Controller
{
    use AdminListing;

    /**
     * Platform-wide invoice list.
     *
     * Backs both Back Office pages: Invoices (every status) and Payments
     * (`?status=paid&sort=paid_at`). There is no separate payments table — an
     * invoice IS the charge record, so Payments is just this list through a
     * narrower lens.
     *
     * - status, payment_method, market: any of ("a,b")
     * - search: owner name/e-mail, invoice number, gateway payment id or order reference
     * - from/to (Y-m-d): on the date named by date_by=created|paid|due;
     *   without it, the sort column when that is paid_at (Payments), else created
     * - sort=created_at|amount|due_date|paid_at (+ dir)
     */
    public function index(Request $request)
    {
        $request->merge($this->listsFrom($request, ['status', 'payment_method', 'market']));

        $validated = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'max:30'],
            'payment_method' => ['sometimes', 'array'],
            'payment_method.*' => ['string', 'max:30'],
            'market' => ['sometimes', 'array'],
            'market.*' => ['string', 'size:2'],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_by' => ['sometimes', 'nullable', Rule::in(['created', 'paid', 'due'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        [$sort, $dir] = $this->sortOf($request, ['created_at', 'amount', 'due_date', 'paid_at']);

        // Payments are browsed by when the money landed; invoices by when raised.
        $dateColumn = match ($validated['date_by'] ?? ($sort === 'paid_at' ? 'paid' : 'created')) {
            'paid' => 'paid_at',
            'due' => 'due_date',
            default => 'created_at',
        };

        $search = trim((string) ($validated['search'] ?? ''));

        $invoices = Invoice::query()
            ->with(['tenant.user', 'subscription.plan'])
            ->when($validated['status'] ?? [], fn ($q, $values) => $q->whereIn('status', $values))
            ->when($validated['payment_method'] ?? [], fn ($q, $values) => $q->whereIn('payment_method', $values))
            ->when($validated['market'] ?? [], fn ($q, $codes) => $q->whereHas(
                'tenant', fn ($t) => $t->whereIn('market_code', array_map('strtoupper', $codes)),
            ))
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('tenant.user', fn ($u) => $u
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->orWhere('payment_id', $search)
                ->orWhere('order_reference', 'like', "%{$search}%")
                ->when(ctype_digit(ltrim($search, '#')), fn ($w2) => $w2->orWhere('id', (int) ltrim($search, '#')))))
            ->when(($validated['from'] ?? null) || ($validated['to'] ?? null), fn ($q) => $this->whereDayRange(
                $q, $dateColumn, $validated['from'] ?? null, $validated['to'] ?? null,
            ))
            ->when($sort === 'created_at', fn ($q) => $q->orderBy('id', $dir))
            ->when($sort === 'amount', fn ($q) => $q->orderBy('amount_cents', $dir))
            ->when(in_array($sort, ['due_date', 'paid_at'], true), fn ($q) => $this->orderByColumnNullsLast($q, $sort, $dir))
            ->orderBy('id', $dir)
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return AdminInvoiceResource::collection($invoices);
    }
}
