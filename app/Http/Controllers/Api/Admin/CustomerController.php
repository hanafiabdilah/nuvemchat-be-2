<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\AuditLog;
use App\Models\Market;
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

        $tenant->load('user')->loadCount(['users', 'connections', 'contacts', 'conversations']);

        return (new CustomerResource($tenant))->response();
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
