<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Market\MarketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminMarketResource;
use App\Models\AuditLog;
use App\Models\Market;
use App\Models\MarketDomain;
use App\Services\Market\MarketCapabilities;
use App\Services\Market\MarketResolver;
use App\Services\Money\ExchangeRates;
use App\Support\Market\CountryCatalog;
use App\Support\PlatformUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The countries the platform sells in, and the domains each is reached on.
 *
 * Opening a market is choosing a country: its currency, calling code and
 * timezones are already known (CountryCatalog), so the form asks only for what
 * is a decision. Two things are fixed once workspaces exist — the code (always)
 * and the currency — and the model refuses them too; the checks here exist to
 * answer with a sentence instead of a 500.
 *
 * A domain row only tells the platform what a domain *means*. DNS and the Caddy
 * block are what bring requests to it (docs/country-domains.md), and neither is
 * visible from here — which is why a domain can be checked from this screen.
 */
class AdminMarketController extends Controller
{
    public function index()
    {
        $markets = Market::query()
            ->with('domains')
            ->withCount('tenants')
            ->orderBy('name')
            ->get();

        return AdminMarketResource::collection($markets);
    }

    /** Everything the market form picks from, so nothing on it is typed. */
    public function meta(): JsonResponse
    {
        $countries = collect(CountryCatalog::all())
            ->map(fn (array $country, string $code) => [
                'code' => $code,
                'currency' => $country['currency'],
                'calling_code' => $country['calling_code'],
                'timezones' => CountryCatalog::timezones($code),
            ])
            ->values();

        return response()->json([
            'countries' => $countries,
            'currencies' => CountryCatalog::currencies(),
            'locales' => collect(config('markets.locales', []))
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values(),
            'statuses' => array_column(MarketStatus::cases(), 'value'),
            // The vocabulary of what a country may sell and connect. Same list
            // everywhere — only the answers differ per market.
            'capabilities' => MarketCapabilities::catalog(),
            'default_market' => MarketResolver::defaultCode(),
            'platform_hosts' => $this->platformHosts(),
        ]);
    }

    /**
     * The exchange rates prices are converted at, one per currency some market
     * sells in.
     *
     * Only prices the platform did not set per country pass through them (an AI
     * run, an API Way instance, a gigabyte of storage): a plan's price is
     * whatever an admin typed for that country, converted from nothing.
     */
    public function rates(): JsonResponse
    {
        $rates = ExchangeRates::all();

        $rows = Market::query()
            ->orderBy('currency')
            ->get()
            ->groupBy(fn (Market $market) => strtoupper($market->currency))
            ->map(fn ($markets, string $currency) => [
                'currency' => $currency,
                'per_usd' => $rates[$currency] ?? null,
                'is_base' => $currency === ExchangeRates::BASE,
                'markets' => $markets->pluck('code')->values(),
            ])
            ->values();

        return response()->json([
            'data' => $rows,
            'base' => ExchangeRates::BASE,
            'rates' => $rates,
        ]);
    }

    public function updateRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rates' => ['required', 'array'],
            'rates.*' => ['required', 'numeric', 'min:0.000001', 'max:100000000'],
        ]);

        ExchangeRates::store($validated['rates']);

        AuditLog::record('markets.rates.update', 'Updated exchange rates', [
            'rates' => $validated['rates'],
        ]);

        return $this->rates();
    }

    public function store(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:2', Rule::in(array_keys(CountryCatalog::all())), Rule::unique('markets', 'code')],
            ...$this->settingsRules(),
            'status' => ['sometimes', 'required', Rule::enum(MarketStatus::class)],
        ], [
            'code.in' => 'Choose a country from the list.',
            'code.unique' => 'This country already has a market.',
        ]);

        $validated = $this->withSanitizedCapabilities($validated);

        $market = Market::create([
            ...$validated,
            // A fact of the country, not a choice: derived, never accepted from
            // the request.
            'phone_country' => CountryCatalog::find($validated['code'])['calling_code'],
            // A new market starts as a draft: it is being configured, and
            // nothing about opening a row should read as a launch.
            'status' => $validated['status'] ?? MarketStatus::Draft->value,
        ]);

        AuditLog::record('markets.create', "Opened market {$market->name} ({$market->code})", [
            'code' => $market->code,
            'currency' => $market->currency,
            'status' => $market->status->value,
        ]);

        return (new AdminMarketResource($this->fresh($market)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Market $market)
    {
        $validated = $request->validate(
            collect($this->settingsRules())
                ->map(fn (array $rules) => ['sometimes', ...$rules])
                ->put('status', ['sometimes', 'required', Rule::enum(MarketStatus::class)])
                ->all(),
        );

        if (isset($validated['currency'])
            && $validated['currency'] !== $market->currency
            && $market->tenants()->exists()) {
            throw ValidationException::withMessages([
                'currency' => 'This market already has workspaces, so its currency is fixed: their balances and invoices are amounts in it.',
            ]);
        }

        $market->fill($this->withSanitizedCapabilities($validated));
        $changes = $market->getDirty();
        $before = array_intersect_key($market->getRawOriginal(), $changes);
        $market->save();

        if ($changes !== []) {
            AuditLog::record('markets.update', "Updated market {$market->name} ({$market->code})", [
                'code' => $market->code,
                'before' => $before,
                'after' => array_map(fn ($value) => $value instanceof MarketStatus ? $value->value : $value, $changes),
            ]);
        }

        return new AdminMarketResource($this->fresh($market));
    }

    /**
     * Only a market nobody joined. One with workspaces is paused instead — the
     * foreign key would refuse anyway, and a workspace's market is permanent.
     */
    public function destroy(Market $market): JsonResponse
    {
        if ($market->code === MarketResolver::defaultCode()) {
            return response()->json([
                'message' => 'This is the default market: every domain that belongs to no market signs up into it.',
                'code' => 'market_is_default',
            ], 422);
        }

        if ($market->tenants()->exists()) {
            return response()->json([
                'message' => 'This market has workspaces and can never be deleted. Pause it instead.',
                'code' => 'market_has_workspaces',
            ], 422);
        }

        $name = $market->name;
        $code = $market->code;
        $domains = $market->domains()->pluck('domain')->all();

        $market->delete();

        // The domains went with it through the foreign key, past MarketDomain's
        // own flush.
        MarketResolver::flush();

        AuditLog::record('markets.delete', "Deleted market {$name} ({$code})", [
            'code' => $code,
            'domains' => $domains,
        ]);

        return response()->json(['message' => 'Market deleted']);
    }

    /* ------------------------------------------------------------------
     | Domains
     * ------------------------------------------------------------------ */

    public function storeDomain(Request $request, Market $market)
    {
        // Stored in the spelling requests are matched in, so validate that one.
        $request->merge(['domain' => MarketResolver::normalizeHost($request->input('domain'))]);

        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/',
            ],
        ], [
            'domain.regex' => 'Enter a domain name, such as chat.pingly.id.',
        ]);

        $domain = $validated['domain'];

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain name, not an IP address.']);
        }

        // The platform host must never be a market's: signups there would join
        // that market, and EnsurePlatformHost never blocks it, so a country's
        // restrictions would silently stop applying.
        if (in_array($domain, $this->platformHosts(), true)) {
            throw ValidationException::withMessages([
                'domain' => 'This is the platform address. Webhooks and the Back Office live on it, so it belongs to no market.',
            ]);
        }

        $taken = MarketDomain::query()->with('market')->where('domain', $domain)->first();

        if ($taken) {
            throw ValidationException::withMessages([
                'domain' => $taken->market_code === $market->code
                    ? 'This domain is already on this market.'
                    : "This domain already belongs to {$taken->market?->name} ({$taken->market_code}).",
            ]);
        }

        // The first domain is the market's address; later ones are aliases
        // until someone says otherwise.
        $market->domains()->create([
            'domain' => $domain,
            'is_primary' => ! $market->domains()->exists(),
        ]);

        AuditLog::record('markets.domain.add', "Added {$domain} to market {$market->code}", [
            'code' => $market->code,
            'domain' => $domain,
        ]);

        return (new AdminMarketResource($this->fresh($market)))->response()->setStatusCode(201);
    }

    /** One primary per market: the address a person is sent to. */
    public function makePrimary(Market $market, MarketDomain $domain)
    {
        foreach ($market->domains as $row) {
            $row->is_primary = $row->is($domain);

            if ($row->isDirty('is_primary')) {
                $row->save();
            }
        }

        AuditLog::record('markets.domain.primary', "Made {$domain->domain} the primary domain of market {$market->code}", [
            'code' => $market->code,
            'domain' => $domain->domain,
        ]);

        return new AdminMarketResource($this->fresh($market));
    }

    public function destroyDomain(Market $market, MarketDomain $domain)
    {
        $wasPrimary = $domain->is_primary;
        $name = $domain->domain;

        $domain->delete();

        // A market with domains always has an address.
        if ($wasPrimary) {
            $next = $market->domains()->orderBy('id')->first();

            if ($next) {
                $next->is_primary = true;
                $next->save();
            }
        }

        AuditLog::record('markets.domain.remove', "Removed {$name} from market {$market->code}", [
            'code' => $market->code,
            'domain' => $name,
        ]);

        return new AdminMarketResource($this->fresh($market));
    }

    /**
     * Does the domain reach this platform, and does it answer as this market?
     *
     * Asks the domain the same question the dashboard asks before login —
     * GET /api/public/bootstrap — over the public internet. It fails at the step
     * that is missing: no DNS or no certificate (no Caddy block) is
     * "unreachable", a Caddy block without the API is an HTTP error, and a
     * domain that answers as another market means this row is not what the
     * platform is reading.
     */
    public function checkDomain(Market $market, MarketDomain $domain): JsonResponse
    {
        $result = [
            'domain' => $domain->domain,
            'ok' => false,
            'problem' => null,
            'http_status' => null,
            'served_market' => null,
            'detail' => null,
            'checked_at' => now()->toIso8601String(),
        ];

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(8)
                ->withoutRedirecting()
                ->get("https://{$domain->domain}/api/public/bootstrap");
        } catch (ConnectionException $e) {
            return response()->json([
                ...$result,
                'problem' => 'unreachable',
                'detail' => Str::limit($e->getMessage(), 240),
            ]);
        }

        $served = $response->json('market.code');

        $result['http_status'] = $response->status();
        $result['served_market'] = is_string($served) ? $served : null;

        if (! $response->successful() || $result['served_market'] === null) {
            return response()->json([...$result, 'problem' => 'http']);
        }

        if ($result['served_market'] !== $market->code) {
            return response()->json([...$result, 'problem' => 'other_market']);
        }

        return response()->json([...$result, 'ok' => true]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, list<mixed>> */
    private function settingsRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', Rule::in(CountryCatalog::currencies())],
            // Minor units, so 100000 is Rp 1.000. Only prices the platform
            // converts pass through it; a price set per market is already the
            // number somebody chose.
            'price_rounding_cents' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000000'],
            // What this country sells and may connect. Only decisions are
            // stored: a key left out falls back to the supplier's own country,
            // so this map is a list of deviations, not a full inventory.
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['boolean'],
            'default_locale' => ['required', 'string', Rule::in(array_keys(config('markets.locales', [])))],
            'default_timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    /**
     * Keep only capability keys that exist, as booleans.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withSanitizedCapabilities(array $validated): array
    {
        if (array_key_exists('capabilities', $validated)) {
            $validated['capabilities'] = MarketCapabilities::sanitize((array) $validated['capabilities']);
        }

        return $validated;
    }

    /**
     * Hosts that are the platform's own, whatever PLATFORM_URL says today.
     *
     * @return list<string>
     */
    private function platformHosts(): array
    {
        return collect([
            PlatformUrl::host(),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('app.frontend_url'), PHP_URL_HOST),
        ])
            ->filter(fn ($host) => is_string($host) && $host !== '')
            ->map(fn (string $host) => MarketResolver::normalizeHost($host))
            ->unique()
            ->values()
            ->all();
    }

    private function fresh(Market $market): Market
    {
        return $market->fresh()->load('domains')->loadCount('tenants');
    }
}
