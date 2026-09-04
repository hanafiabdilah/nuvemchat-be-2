<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiwayNumbersException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\VirtualNumber;
use App\Services\VirtualNumbers\ApiwayNumbersClient;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\NumberPricing;
use App\Services\VirtualNumbers\VirtualNumberService;
use Illuminate\Http\Request;

/**
 * The platform's side of the numbers business: what it is paying API Way for,
 * what it is charging for it, and whether the pipe between the two is alive.
 *
 * Cost lives here and nowhere else a tenant can reach. It is also the only
 * place the shared account's ceiling is visible — every workspace draws from
 * the same inventory, so "how many numbers are live" is an operator's number,
 * not a customer's.
 */
class AdminNumbersController extends Controller
{
    public function __construct(
        private readonly ApiwayNumbersClient $client,
        private readonly VirtualNumberService $numbers,
    ) {}

    /**
     * Every rented number, with its tenant, cost and margin.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'max:20'],
            'search' => ['sometimes', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = VirtualNumber::query()
            ->with('tenant:id,name')
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where('msisdn', 'like', "%{$term}%"))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $rows->getCollection()->transform(fn (VirtualNumber $row) => [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'tenant_name' => $row->tenant?->name,
            'provider_number_id' => $row->provider_number_id,
            'msisdn' => $row->msisdn,
            'app' => $row->app,
            'ddd' => $row->ddd,
            'region' => $row->region,
            'status' => $row->status->value,
            'cost_cents' => $row->cost_cents,
            'price_cents' => $row->price_cents,
            // Precomputed rather than left to the client: a margin the Back
            // Office derives and a margin the pricing class derives are two
            // numbers that will disagree the first time an override changes.
            'margin_cents' => $row->price_cents - $row->cost_cents,
            'renews_at' => $row->renews_at?->toISOString(),
            'cancelled_at' => $row->cancelled_at?->toISOString(),
            'cancel_reason' => $row->cancelReason(),
            'created_at' => $row->created_at?->toISOString(),
        ]);

        return response()->json($rows);
    }

    /**
     * What the platform is currently committed to: live numbers and the monthly
     * bill they add up to, against what tenants are paying for them.
     */
    public function summary()
    {
        $live = VirtualNumber::query()->live()->get(['cost_cents', 'price_cents']);

        return response()->json([
            'data' => [
                'configured' => ApiwayNumbersConfig::isConfigured(),
                'live_count' => $live->count(),
                'monthly_cost_cents' => (int) $live->sum('cost_cents'),
                'monthly_revenue_cents' => (int) $live->sum('price_cents'),
                'pending_count' => VirtualNumber::query()
                    ->where('status', \App\Enums\Numbers\VirtualNumberStatus::Pending->value)
                    ->count(),
            ],
        ]);
    }

    /**
     * Prove the stored credentials work, and show what the catalog costs.
     *
     * Deliberately does the login rather than reading a cached token: the
     * question this button answers is "does the e-mail and password in this
     * form still work", and a cached session would answer a different one.
     */
    public function test()
    {
        try {
            $account = $this->client->login();
            $catalog = $this->numbers->catalog(fresh: true);
        } catch (ApiwayNumbersException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getErrorCode() === ApiwayNumbersException::UNCONFIGURED ? 503 : 502);
        }

        $costCents = (int) ($catalog['price_cents'] ?? 0);

        return response()->json([
            'data' => [
                'account' => [
                    'user' => $account['user']['email'] ?? null,
                    'tenant' => $account['tenant']['name'] ?? null,
                ],
                'currency' => $catalog['currency'] ?? 'BRL',
                'cost_cents' => $costCents,
                'regions' => $catalog['regions'] ?? [],
                // Cost, sale price and margin side by side, per app — the whole
                // point of the pricing editor next to it.
                'apps' => array_map(fn (array $app) => [
                    'id' => $app['id'] ?? '',
                    'label' => $app['label'] ?? ($app['id'] ?? ''),
                    'cost_cents' => $costCents,
                    'price_cents' => NumberPricing::saleCents((string) ($app['id'] ?? ''), $costCents),
                    'margin_cents' => NumberPricing::marginCents((string) ($app['id'] ?? ''), $costCents),
                    'has_override' => array_key_exists((string) ($app['id'] ?? ''), NumberPricing::appPrices()),
                ], $catalog['apps'] ?? []),
            ],
        ]);
    }

    /** Where API Way currently pushes SMS, and whether a secret is stored here. */
    public function webhook()
    {
        try {
            $remote = $this->client->getWebhook();
        } catch (ApiwayNumbersException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], 502);
        }

        return response()->json([
            'data' => [
                'remote_url' => $remote['url'] ?? null,
                'enabled' => $remote['enabled'] ?? false,
                'secret_preview' => $remote['secret_preview'] ?? null,
                // Ours, not theirs. A URL registered upstream whose secret we
                // never stored means every push is rejected at our door, and
                // this is the only place that mismatch is visible.
                'local_secret_set' => ! empty(ApiwayNumbersConfig::webhookSecret()),
                'local_url' => ApiwayNumbersConfig::webhookUrl(),
                'expected_url' => route('webhook.apiway-numbers'),
            ],
        ]);
    }

    /**
     * Register (or rotate) the receive URL.
     *
     * The raw secret comes back exactly once, from this call — the GET only
     * ever returns a preview — so it is stored in the same breath. Losing it
     * means re-registering, because there is no way to ask for it again.
     */
    public function registerWebhook(Request $request)
    {
        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:255'],
            'rotate' => ['sometimes', 'boolean'],
        ]);

        $url = $validated['url'] ?? route('webhook.apiway-numbers');

        try {
            $result = $this->client->setWebhook($url, (bool) ($validated['rotate'] ?? false));
        } catch (ApiwayNumbersException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], 502);
        }

        $secret = $result['secret'] ?? null;

        if (! empty($secret)) {
            Setting::set(ApiwayNumbersConfig::KEY_WEBHOOK_SECRET, $secret);
        }

        Setting::set(ApiwayNumbersConfig::KEY_WEBHOOK_URL, $url);

        AuditLog::record('numbers.webhook.register', "Registered the API Way numbers webhook at {$url}");

        return response()->json([
            'data' => [
                'url' => $result['url'] ?? $url,
                'enabled' => $result['enabled'] ?? true,
                'secret_stored' => ! empty($secret),
            ],
        ]);
    }
}
