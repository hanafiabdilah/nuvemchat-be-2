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

    /** Users who can be made affiliators: picked from a search, never typed as an id. */
    public function candidates(Request $request)
    {
        $search = trim((string) $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ])['search'] ?? '');

        $users = User::query()
            ->whereNotNull('tenant_id')
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
                    ->selectRaw('currency, SUM(amount_cents) as cents, COUNT(*) as charges')
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

        $commissions = AffiliateCommission::query()
            ->with('tenant:id,billing_name,user_id', 'tenant.user:id,name')
            ->where('affiliate_id', $affiliate->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (AffiliateCommission $row) => [
                'id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'customer' => $row->tenant?->billing_name ?: $row->tenant?->user?->name,
                'invoice_id' => $row->invoice_id,
                'clawback' => $row->reversal_of_id !== null,
                'base_cents' => $row->base_cents,
                'percent' => $row->percent,
                'amount_cents' => $row->amount_cents,
                'currency' => $row->currency,
                'status' => $row->status === CommissionStatus::Pending
                    ? ($row->available_at && $row->available_at->isPast() ? 'available' : 'holding')
                    : $row->status->value,
                'available_at' => $row->available_at?->toIso8601String(),
                'paid_at' => $row->paid_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $this->summary($affiliate),
            'referrals' => $referrals,
            'commissions' => $commissions,
            'payouts' => $affiliate->payouts()->with('admin:id,name')->orderByDesc('paid_at')->limit(100)->get()->map(fn ($payout) => [
                'id' => $payout->id,
                'amount_cents' => $payout->amount_cents,
                'currency' => $payout->currency,
                'method' => $payout->method,
                'reference' => $payout->reference,
                'note' => $payout->note,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'admin' => $payout->admin?->name,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        if (filled($request->input('code'))) {
            $request->merge(['code' => Affiliate::normalizeCode($request->input('code'))]);
        }

        $data = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->whereNotNull('tenant_id'),
                Rule::unique('affiliates', 'user_id'),
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
