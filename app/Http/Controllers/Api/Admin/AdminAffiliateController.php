<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Affiliate\CommissionStatus;
use App\Enums\Affiliate\CommissionType;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Affiliates\AffiliateCommissions;
use App\Services\Affiliates\AffiliateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Affiliators in the Back Office: who is one and on what terms, what their
 * link brought in, what they are owed, and the payouts made to them by hand.
 *
 * Terms (percentage, once/recurring, months) only shape commissions earned
 * from now on — each commission keeps the percentage it was earned at.
 */
class AdminAffiliateController extends Controller
{
    public function __construct(protected AffiliateCommissions $commissions) {}

    public function index()
    {
        $affiliates = Affiliate::query()->with('user:id,name,email,tenant_id')->orderByDesc('id')->get();

        return response()->json([
            'data' => $affiliates->map(fn (Affiliate $affiliate) => $this->summary($affiliate)),
            'settings' => ['hold_days' => AffiliateSettings::holdDays()],
        ]);
    }

    /**
     * Users who can be made affiliators: picked from a search, never typed as
     * an id. Workspace owners only — an agent is somebody else's employee, and
     * the commission is a deal with the business, not with its staff.
     */
    public function candidates(Request $request)
    {
        $search = trim((string) $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ])['search'] ?? '');

        $users = $this->owners()
            ->whereNotIn('id', Affiliate::query()->whereNotNull('user_id')->select('user_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'tenant_id']);

        return response()->json(['data' => $users]);
    }

    public function show(Affiliate $affiliate)
    {
        $affiliate->loadMissing('user:id,name,email,tenant_id');

        $referrals = Tenant::query()
            ->with('user:id,name,email')
            ->where('referred_by_affiliate_id', $affiliate->id)
            ->orderByDesc('referred_at')
            ->limit(200)
            ->get()
            ->map(function (Tenant $tenant) use ($affiliate) {
                $earned = AffiliateCommission::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('tenant_id', $tenant->id)
                    ->where('status', '!=', CommissionStatus::Reversed->value)
                    ->groupBy('currency')
                    ->selectRaw('currency, SUM(amount_cents) as cents, COUNT(invoice_id) as charges')
                    ->get();

                return [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->billing_name ?: $tenant->user?->name,
                    'email' => $tenant->user?->email,
                    'referred_at' => $tenant->referred_at?->toIso8601String(),
                    'charges' => (int) $earned->sum('charges'),
                    'earned' => $earned->map(fn ($row) => ['currency' => $row->currency, 'cents' => (int) $row->cents])->values(),
                ];
            });

        return response()->json([
            'data' => $this->summary($affiliate),
            'referrals' => $referrals,
            'activity' => $this->commissions->activity($affiliate),
        ]);
    }

    /**
     * Every affiliator's commissions in one list: what is still owed and what
     * was paid, filterable by affiliator and state, with the totals per
     * currency for the same affiliator filter.
     */
    public function commissions(Request $request)
    {
        $data = $request->validate([
            'affiliate_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['holding', 'available', 'paid', 'reversed'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $affiliateId = $data['affiliate_id'] ?? null;
        $statuses = $data['status'] ?? [];
        $search = trim((string) ($data['search'] ?? ''));

        $scoped = fn () => AffiliateCommission::query()
            ->when($affiliateId, fn ($q) => $q->where('affiliate_id', $affiliateId));

        $query = $scoped()
            ->with([
                'affiliate:id,code,user_id',
                'affiliate.user:id,name,email',
                'tenant:id,billing_name,user_id',
                'tenant.user:id,name,email',
                'payout:id,method,reference,admin_id,paid_at',
                'payout.admin:id,name',
            ])
            ->when($statuses !== [], fn ($q) => $q->where(fn ($w) => collect($statuses)->each(fn (string $status) => $w->orWhere(fn ($one) => match ($status) {
                'holding' => $one->holding(),
                'available' => $one->available(),
                'paid' => $one->where('status', CommissionStatus::Paid->value),
                'reversed' => $one->where('status', CommissionStatus::Reversed->value),
            }))))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('tenant', fn ($t) => $t->where('billing_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
                ->orWhereHas('affiliate', fn ($a) => $a->where('code', 'like', '%'.strtolower($search).'%')
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate(50);

        $totals = [];

        foreach ($scoped()->where('status', '!=', CommissionStatus::Reversed->value)->get(['currency', 'status', 'available_at', 'amount_cents']) as $row) {
            $bucket = &$totals[$row->currency];
            $bucket ??= ['currency' => $row->currency, 'holding_cents' => 0, 'available_cents' => 0, 'paid_cents' => 0];
            $bucket[match ($row->state()) {
                'paid' => 'paid_cents',
                'available' => 'available_cents',
                default => 'holding_cents',
            }] += $row->amount_cents;
            unset($bucket);
        }

        ksort($totals);

        return response()->json([
            'data' => collect($page->items())->map(fn (AffiliateCommission $row) => [
                'id' => $row->id,
                'affiliate' => $row->affiliate ? [
                    'id' => $row->affiliate->id,
                    'code' => $row->affiliate->code,
                    'name' => $row->affiliate->user?->name,
                    'email' => $row->affiliate->user?->email,
                ] : null,
                'tenant_id' => $row->tenant_id,
                'customer' => $row->tenant?->billing_name ?: $row->tenant?->user?->name,
                'invoice_id' => $row->invoice_id,
                'clawback' => $row->reversal_of_id !== null,
                'base_cents' => $row->base_cents,
                'percent' => $row->percent,
                'amount_cents' => $row->amount_cents,
                'currency' => $row->currency,
                'status' => $row->state(),
                'available_at' => $row->available_at?->toIso8601String(),
                'paid_at' => $row->paid_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
                'payout' => $row->payout ? [
                    'id' => $row->payout->id,
                    'method' => $row->payout->method,
                    'reference' => $row->payout->reference,
                    'admin' => $row->payout->admin?->name,
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'totals' => array_values($totals),
        ]);
    }

    /** Mark the chosen commissions as paid: the money moved outside the system. */
    public function payCommissions(Request $request)
    {
        $data = $request->validate([
            'commission_ids' => ['required', 'array', 'min:1', 'max:500'],
            'commission_ids.*' => ['integer'],
            'method' => ['required', Rule::in(['pix', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = $this->commissions->payCommissions(
            $data['commission_ids'],
            $data['method'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
            $request->user(),
        );

        $affiliate = $payout->affiliate;

        AuditLog::record('affiliates.payout', "Recorded payout to affiliate {$affiliate?->code}", [
            'affiliate_id' => $payout->affiliate_id,
            'payout_id' => $payout->id,
            'commission_ids' => $payout->commissions()->pluck('id')->all(),
            'amount_cents' => $payout->amount_cents,
            'currency' => $payout->currency,
            'method' => $payout->method,
            'reference' => $payout->reference,
        ]);

        return response()->json(['data' => $payout], 201);
    }

    public function store(Request $request)
    {
        if (filled($request->input('code'))) {
            $request->merge(['code' => Affiliate::normalizeCode($request->input('code'))]);
        }

        $data = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::unique('affiliates', 'user_id'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $this->owners()->whereKey($value)->exists()) {
                        $fail(__('Only a workspace owner can be an affiliate.'));
                    }
                },
            ],
            ...$this->termRules(),
            'code' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{3,32}$/', Rule::unique('affiliates', 'code')],
        ], $this->messages());

        $user = User::findOrFail($data['user_id']);
        $code = filled($data['code'] ?? null) ? Affiliate::normalizeCode($data['code']) : $this->freeCode($user);

        $affiliate = Affiliate::create([
            ...$this->terms($data),
            'user_id' => $user->id,
            'code' => $code,
            'created_by_admin_id' => $request->user()?->getKey(),
        ]);

        AuditLog::record('affiliates.create', "Made {$user->email} an affiliate ({$code})", [
            'affiliate_id' => $affiliate->id,
            ...$this->auditFields($affiliate),
        ]);

        return response()->json(['data' => $this->summary($affiliate->fresh('user'))], 201);
    }

    public function update(Request $request, Affiliate $affiliate)
    {
        if ($request->has('code')) {
            $request->merge(['code' => Affiliate::normalizeCode($request->input('code'))]);
        }

        $data = $request->validate([
            ...$this->termRules(),
            'code' => ['required', 'string', 'regex:/^[a-z0-9_-]{3,32}$/', Rule::unique('affiliates', 'code')->ignore($affiliate->id)],
        ], $this->messages());

        $before = $this->auditFields($affiliate);
        $affiliate->update([...$this->terms($data), 'code' => $data['code']]);

        AuditLog::record('affiliates.update', "Updated affiliate {$affiliate->code}", [
            'affiliate_id' => $affiliate->id,
            'before' => $before,
            'after' => $this->auditFields($affiliate->fresh()),
        ]);

        return response()->json(['data' => $this->summary($affiliate->fresh('user'))]);
    }

    public function destroy(Affiliate $affiliate)
    {
        // Workspaces are locked to it and money is on record against it: it
        // can be switched off, not erased.
        if ($affiliate->referrals()->exists() || $affiliate->commissions()->exists() || $affiliate->payouts()->exists()) {
            return response()->json([
                'message' => __('This affiliate already brought in workspaces or earned commissions. Deactivate it instead of deleting it.'),
                'code' => 'affiliate_in_use',
            ], 422);
        }

        AuditLog::record('affiliates.delete', "Deleted affiliate {$affiliate->code}", [
            'affiliate_id' => $affiliate->id,
            ...$this->auditFields($affiliate),
        ]);

        $affiliate->delete();

        return response()->json(['message' => 'Affiliate deleted']);
    }

    /** Record a payout made by hand: everything available in one currency. */
    public function recordPayout(Request $request, Affiliate $affiliate)
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', Rule::in(['pix', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = $this->commissions->recordPayout(
            $affiliate,
            $data['currency'],
            $data['method'],
            $data['reference'] ?? null,
            $data['note'] ?? null,
            $request->user(),
        );

        AuditLog::record('affiliates.payout', "Recorded payout to affiliate {$affiliate->code}", [
            'affiliate_id' => $affiliate->id,
            'payout_id' => $payout->id,
            'amount_cents' => $payout->amount_cents,
            'currency' => $payout->currency,
            'method' => $payout->method,
            'reference' => $payout->reference,
        ]);

        return response()->json(['data' => $payout], 201);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'hold_days' => ['required', 'integer', 'min:0', 'max:'.AffiliateSettings::MAX_HOLD_DAYS],
        ]);

        $before = AffiliateSettings::holdDays();
        AffiliateSettings::setHoldDays((int) $data['hold_days']);

        AuditLog::record('affiliates.settings', 'Updated affiliate settings', [
            'hold_days' => ['before' => $before, 'after' => AffiliateSettings::holdDays()],
        ]);

        return response()->json(['data' => ['hold_days' => AffiliateSettings::holdDays()]]);
    }

    /** @return array<string, mixed> */
    protected function summary(Affiliate $affiliate): array
    {
        return [
            'id' => $affiliate->id,
            'code' => $affiliate->code,
            'link' => $affiliate->link(),
            'is_active' => $affiliate->is_active,
            'commission_percent' => $affiliate->commission_percent,
            'commission_type' => $affiliate->commission_type->value,
            'recurring_months' => $affiliate->recurring_months,
            'note' => $affiliate->note,
            'payout_details' => $affiliate->payout_details,
            'user' => $affiliate->user ? [
                'id' => $affiliate->user->id,
                'name' => $affiliate->user->name,
                'email' => $affiliate->user->email,
                'tenant_id' => $affiliate->user->tenant_id,
            ] : null,
            'stats' => $this->commissions->stats($affiliate),
            'balances' => $this->commissions->balances($affiliate),
            'created_at' => $affiliate->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function termRules(): array
    {
        return [
            'commission_percent' => ['required', 'integer', 'min:1', 'max:90'],
            'commission_type' => ['required', Rule::enum(CommissionType::class)],
            // Empty = for as long as the workspace pays.
            'recurring_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    protected function terms(array $data): array
    {
        $recurring = $data['commission_type'] === CommissionType::Recurring->value;

        return [
            'commission_percent' => (int) $data['commission_percent'],
            'commission_type' => $data['commission_type'],
            'recurring_months' => $recurring && filled($data['recurring_months'] ?? null) ? (int) $data['recurring_months'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'note' => $data['note'] ?? null,
        ];
    }

    /** @return array<string, string> */
    /**
     * Owners of a workspace: the account that created it, or anyone holding
     * the owner role in it. Both, because the role can be given to a second
     * person and the creator always counts.
     */
    protected function owners()
    {
        return User::query()
            ->whereNotNull('tenant_id')
            ->where(fn ($q) => $q
                ->whereIn('id', Tenant::query()->select('user_id'))
                ->orWhereHas('roles', fn ($r) => $r->where('name', 'owner')));
    }

    protected function messages(): array
    {
        return ['code.regex' => __('Use 3 to 32 letters, numbers, - or _.')];
    }

    /** A readable, unused code from the person's name: "ana-duarte", "ana-duarte-7k2". */
    protected function freeCode(User $user): string
    {
        $base = Str::limit(Str::slug($user->name ?: 'pingly'), 24, '') ?: 'pingly';
        $code = strlen($base) >= 3 ? $base : $base.'-'.Str::lower(Str::random(3));

        while (Affiliate::query()->where('code', $code)->exists()) {
            $code = $base.'-'.Str::lower(Str::random(3));
        }

        return $code;
    }

    /** @return array<string, mixed> */
    protected function auditFields(Affiliate $affiliate): array
    {
        return [
            'user_id' => $affiliate->user_id,
            'code' => $affiliate->code,
            'is_active' => $affiliate->is_active,
            'commission_percent' => $affiliate->commission_percent,
            'commission_type' => $affiliate->commission_type?->value,
            'recurring_months' => $affiliate->recurring_months,
        ];
    }
}
