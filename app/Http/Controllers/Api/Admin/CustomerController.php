<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\AuditLog;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    /**
     * Paginated list of every customer (tenant) on the platform.
     *
     * Supports ?search= (owner name/email/WhatsApp number), ?per_page=, and ?sort=
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
                // Numbers are stored as bare digits, so a search typed with spaces,
                // dashes or a leading + would never match without stripping them first.
                $digits = preg_replace('/\D+/', '', $search);

                $query->whereHas('user', function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->when($digits !== '', fn ($q2) => $q2->orWhere('whatsapp_number', 'like', "%{$digits}%"));
                });
            })
            // The Markets page links its workspace count here.
            ->when($request->filled('market'), fn ($query) => $query->where('market_code', strtoupper((string) $request->query('market'))))
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
}
