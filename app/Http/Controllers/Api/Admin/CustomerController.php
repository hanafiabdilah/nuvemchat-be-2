<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\SubscriptionStatus;
use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\AuditLog;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Market\MarketDocuments;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    use AdminListing;

    /**
     * Paginated list of every customer (tenant) on the platform.
     *
     * Not tenant-scoped — this is a platform admin view. Every filter applies
     * on the server, before paging: a filter that only narrowed the 20 rows on
     * screen would say "no customers" while the next page had matches.
     *
     * - search: owner name, e-mail or WhatsApp number
     * - status: the current subscription's status, any of; "none" = no plan
     * - plan_id, market: any of
     * - verified: yes|no — the owner's WhatsApp confirmed by OTP
     * - activity: active|inactive — has had at least one conversation
     * - created_from/created_to, period_end_from/period_end_to: Y-m-d, inclusive
     * - sort: created_at|name|email|plan|status|period_end|users|connections|
     *   conversations (+ dir=asc|desc); newest|oldest still accepted
     */
    public function index(Request $request)
    {
        $request->merge($this->listsFrom($request, ['status', 'plan_id', 'market']));

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in([...array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::cases()), 'none'])],
            'plan_id' => ['sometimes', 'array'],
            'plan_id.*' => ['integer'],
            'market' => ['sometimes', 'array'],
            'market.*' => ['string', 'size:2'],
            'verified' => ['sometimes', 'nullable', Rule::in(['yes', 'no'])],
            'activity' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_end_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_end_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filtered($validated)
            // The plan and whether it is being paid is the first thing an
            // operator reads in this list, before any count.
            ->with($this->relations($request))
            ->withCount(['users', 'connections', 'contacts', 'conversations']);

        $this->sort($query, $request);

        return CustomerResource::collection(
            $query->paginate($validated['per_page'] ?? 15)->withQueryString(),
        );
    }

    /**
     * What the list's filters pick from, and how many customers signed up this
     * week (shown beside the total).
     *
     * Served here, under the same permission as the list, rather than read
     * from /admin/plans and /admin/markets — those sit behind permissions that
     * edit a plan or open a country, which the person reading customers may
     * not hold.
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total' => Tenant::query()->count(),
                'new_7d' => Tenant::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'filters' => [
                    'statuses' => array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::cases()),
                    // Retired plans included: their customers are still here.
                    'plans' => Plan::query()
                        ->orderByDesc('is_active')
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->get(['id', 'name', 'is_active'])
                        ->map(fn (Plan $plan) => ['id' => $plan->id, 'name' => $plan->name, 'is_active' => (bool) $plan->is_active]),
                    'markets' => Market::query()
                        ->orderBy('name')
                        ->get(['code', 'name'])
                        ->map(fn (Market $market) => ['code' => $market->code, 'name' => $market->name]),
                ],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $f  validated filters */
    private function filtered(array $f)
    {
        $search = trim((string) ($f['search'] ?? ''));
        $statuses = $f['status'] ?? [];
        $withoutPlan = in_array('none', $statuses, true);
        $statuses = array_values(array_diff($statuses, ['none']));

        return Tenant::query()
            ->when($search !== '', function ($query) use ($search) {
                // Numbers are stored as bare digits, so a search typed with spaces,
                // dashes or a leading + would never match without stripping them first.
                $digits = preg_replace('/\D+/', '', $search);

                $query->whereHas('user', function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->when($digits !== '', fn ($q2) => $q2->orWhere('whatsapp_number', 'like', "%{$digits}%"));
                });
            })
            ->when($statuses !== [] || $withoutPlan, fn ($query) => $query->where(function ($q) use ($statuses, $withoutPlan) {
                if ($statuses !== []) {
                    $q->whereHas('currentSubscription', fn ($s) => $s->whereIn('status', $statuses));
                }
                if ($withoutPlan) {
                    $q->orWhereDoesntHave('currentSubscription');
                }
            }))
            ->when($f['plan_id'] ?? [], fn ($query, $ids) => $query->whereHas('currentSubscription', fn ($s) => $s->whereIn('plan_id', $ids)))
            // The Markets page links its workspace count here.
            ->when($f['market'] ?? [], fn ($query, $codes) => $query->whereIn('market_code', array_map('strtoupper', $codes)))
            ->when(($f['verified'] ?? null) === 'yes', fn ($query) => $query->whereHas('user', fn ($q) => $q->whereNotNull('whatsapp_verified_at')))
            ->when(($f['verified'] ?? null) === 'no', fn ($query) => $query->whereDoesntHave('user', fn ($q) => $q->whereNotNull('whatsapp_verified_at')))
            ->when(($f['activity'] ?? null) === 'active', fn ($query) => $query->has('conversations'))
            ->when(($f['activity'] ?? null) === 'inactive', fn ($query) => $query->doesntHave('conversations'))
            ->when(($f['created_from'] ?? null) || ($f['created_to'] ?? null), fn ($query) => $this->whereDayRange(
                $query, 'tenants.created_at', $f['created_from'] ?? null, $f['created_to'] ?? null,
            ))
            ->when(($f['period_end_from'] ?? null) || ($f['period_end_to'] ?? null), fn ($query) => $query->whereHas(
                'currentSubscription',
                fn ($s) => $this->whereDayRange($s, 'current_period_end', $f['period_end_from'] ?? null, $f['period_end_to'] ?? null),
            ));
    }

    private function sort($query, Request $request): void
    {
        $sort = (string) $request->query('sort', 'created_at');
        $dir = $this->direction($request);

        // The two values the list sent before columns were sortable.
        if ($sort === 'newest' || $sort === 'oldest') {
            [$sort, $dir] = ['created_at', $sort === 'oldest' ? 'asc' : 'desc'];
        }

        $owner = fn (string $column) => User::query()->select($column)->whereColumn('users.id', 'tenants.user_id')->limit(1);
        $current = fn (string $column) => Subscription::query()->select($column)->whereColumn('subscriptions.id', 'tenants.current_subscription_id')->limit(1);

        match ($sort) {
            'name' => $query->orderBy($owner('name'), $dir),
            'email' => $query->orderBy($owner('email'), $dir),
            'plan' => $this->orderBySubqueryNullsLast(
                $query,
                Plan::query()->select('plans.name')
                    ->join('subscriptions', 'subscriptions.plan_id', '=', 'plans.id')
                    ->whereColumn('subscriptions.id', 'tenants.current_subscription_id')
                    ->limit(1),
                $dir,
            ),
            'status' => $this->orderBySubqueryNullsLast($query, $current('status'), $dir),
            'period_end' => $this->orderBySubqueryNullsLast($query, $current('current_period_end'), $dir),
            'users' => $query->orderBy('users_count', $dir),
            'connections' => $query->orderBy('connections_count', $dir),
            'conversations' => $query->orderBy('conversations_count', $dir),
            default => $query->orderBy('tenants.id', $dir),
        };

        // Stable pages: equal names or counts must not swap rows between pages.
        $query->orderBy('tenants.id', $dir === 'asc' ? 'asc' : 'desc');
    }

    /**
     * Detail for a single customer (tenant).
     */
    public function show(Tenant $tenant)
    {
        $tenant->load($this->relations(request()))
            ->loadCount(['users', 'connections', 'contacts', 'conversations']);

        return new CustomerResource($tenant);
    }

    /**
     * What the "Add customer" form picks from. Served here rather than read
     * from /admin/markets, which sits behind bo.markets.manage — the person
     * onboarding a customer is not necessarily allowed to open a country.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'markets' => Market::query()
                ->orderBy('name')
                ->get(['code', 'name', 'phone_country', 'status'])
                ->map(fn (Market $market) => [
                    'code' => $market->code,
                    'name' => $market->name,
                    'calling_code' => $market->phone_country,
                    'status' => $market->status,
                ]),
            'default_market' => strtoupper((string) config('markets.default', 'BR')),
        ]);
    }

    /**
     * Open a workspace on someone's behalf: the owner account and its tenant,
     * the same two rows public signup writes.
     *
     * Two things differ from signup, both on purpose. The market is chosen
     * here instead of read from the domain — the Back Office lives on the
     * platform host, which would put every workspace opened from it in the
     * default country, permanently. And no OTP is sent: the operator decides
     * whether the number is already confirmed (they spoke to the customer on
     * it) or whether the owner should confirm it themselves on first sign-in,
     * which the WhatsApp gate already asks them to do.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'whatsapp_verified' => ['sometimes', 'boolean'],
            'market_code' => ['required', 'string', Rule::exists('markets', 'code')],
        ]);

        $number = OtpService::normalizeNumber((string) ($validated['whatsapp_number'] ?? ''));

        if ($number !== '' && (strlen($number) < 8 || strlen($number) > 15)) {
            return response()->json([
                'message' => __('Enter the WhatsApp number with its country code.'),
                'errors' => ['whatsapp_number' => [__('Enter the WhatsApp number with its country code.')]],
            ], 422);
        }

        $tenant = DB::transaction(function () use ($validated, $number) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'], // hashed via model cast
                'whatsapp_number' => $number !== '' ? $number : null,
                'whatsapp_verified_at' => $number !== '' && ($validated['whatsapp_verified'] ?? false) ? now() : null,
            ]);

            $tenant = new Tenant(['user_id' => $user->id]);
            // Not fillable on purpose; set explicitly so the request's host
            // (the platform's own) is not what decides the country.
            $tenant->market_code = strtoupper($validated['market_code']);
            $tenant->save();

            $user->tenant_id = $tenant->id;
            $user->save();

            $user->assignRole('owner');

            return $tenant;
        });

        $tenant->load('user')->loadCount(['users', 'connections', 'contacts', 'conversations']);

        AuditLog::record('customers.create', "Created customer {$tenant->user->email} (tenant #{$tenant->id})", [
            'tenant_id' => $tenant->id,
            'user_id' => $tenant->user->id,
            'market_code' => $tenant->market_code,
            'whatsapp_verified' => $tenant->user->whatsapp_verified_at !== null,
        ]);

        return (new CustomerResource($tenant))->response()->setStatusCode(201);
    }

    /**
     * Correct what a customer registered with: the owner's name, e-mail and
     * WhatsApp number, and the workspace's billing name and tax document.
     *
     * The market is not here and never will be — a workspace does not change
     * country. Neither is the password, which the Users page already resets.
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $owner = $tenant->user;

        if (! $owner) {
            return response()->json(['message' => __('This workspace has no owner account.')], 422);
        }

        $market = $tenant->market_code;
        $needsDocument = MarketDocuments::required($market);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'whatsapp_verified' => ['sometimes', 'boolean'],
            'billing_name' => ['nullable', 'string', 'max:191'],
            'billing_document_type' => ['nullable', 'string', 'max:8'],
            'billing_document_number' => ['nullable', 'string', 'max:32'],
        ]);

        $number = OtpService::normalizeNumber((string) ($validated['whatsapp_number'] ?? ''));

        if ($number !== '' && (strlen($number) < 8 || strlen($number) > 15)) {
            return $this->fieldError('whatsapp_number', __('Enter the WhatsApp number with its country code.'));
        }

        // A document is a pair. Both blank clears it (a workspace that has not
        // paid yet has none); one without the other is a half-typed form.
        $documentType = strtoupper(trim((string) ($validated['billing_document_type'] ?? '')));
        $documentNumber = preg_replace('/\D/', '', (string) ($validated['billing_document_number'] ?? '')) ?? '';

        if (! $needsDocument) {
            $documentType = $documentNumber = '';
        } elseif ($documentType !== '' || $documentNumber !== '') {
            if ($documentType === '') {
                return $this->fieldError('billing_document_type', __('Choose one of: :types.', [
                    'types' => implode(', ', MarketDocuments::codes($market)),
                ]));
            }

            $problem = MarketDocuments::problem($market, $documentType, $documentNumber);

            if ($problem !== null) {
                return $this->fieldError('billing_document_number', $problem);
            }
        }

        $previous = ['email' => $owner->email, 'whatsapp_number' => $owner->whatsapp_number];

        $owner->name = $validated['name'];
        $owner->email = $validated['email'];
        $owner->whatsapp_number = $number !== '' ? $number : null;

        // The tick is the operator vouching for the number as it now stands.
        // A number that changed loses the old confirmation either way; one
        // that did not keeps the date it was really confirmed on.
        $verified = $number !== '' && ($validated['whatsapp_verified']
            ?? ($owner->whatsapp_verified_at !== null && ! $owner->isDirty('whatsapp_number')));

        if (! $verified) {
            $owner->whatsapp_verified_at = null;
        } elseif ($owner->isDirty('whatsapp_number') || $owner->whatsapp_verified_at === null) {
            $owner->whatsapp_verified_at = now();
        }

        $tenant->billing_name = filled($validated['billing_name'] ?? null) ? trim($validated['billing_name']) : null;
        $tenant->billing_document_type = $documentType !== '' ? $documentType : null;
        $tenant->billing_document_number = $documentNumber !== '' ? $documentNumber : null;

        $changingEmail = $owner->isDirty('email');
        $changed = array_merge(array_keys($owner->getDirty()), array_keys($tenant->getDirty()));

        DB::transaction(function () use ($owner, $tenant) {
            $owner->save();
            $tenant->save();
        });

        // Same rule as every other e-mail change: sessions opened under the
        // old address stop working.
        if ($changingEmail) {
            $owner->tokens()->delete();
        }

        if ($changed !== []) {
            AuditLog::record('customers.update', "Updated customer {$owner->email} (tenant #{$tenant->id})", array_filter([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'changed' => $changed,
                'previous_email' => $changingEmail ? $previous['email'] : null,
                'previous_whatsapp_number' => in_array('whatsapp_number', $changed, true) ? $previous['whatsapp_number'] : null,
            ]));
        }

        // Same shape as show(): the detail page swaps its copy for this one.
        $tenant->load($this->relations(request()))
            ->loadCount(['users', 'connections', 'contacts', 'conversations']);

        return (new CustomerResource($tenant))->response();
    }

    /**
     * What a customer row is loaded with. The balance is money held for the
     * customer, so it only comes along for roles that manage balances — the
     * same gate as the Balance tab it links to.
     *
     * @return list<string>
     */
    private function relations(Request $request): array
    {
        $relations = ['user', 'currentSubscription.plan:id,name'];

        if ($request->user()?->checkPermissionTo('bo.credits.manage')) {
            $relations[] = 'creditWallet';
        }

        return $relations;
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
