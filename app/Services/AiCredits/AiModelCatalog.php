<?php

namespace App\Services\AiCredits;

use App\Models\AiModelPrice;
use App\Models\AiTokenPoolKey;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Support\Facades\Log;

/**
 * The list of providers and models the Back Office picks from.
 *
 * Every admin form that names a model — the trained-agent blueprint, a pool
 * key's default model, a model's price — used to be a free-text box. That is
 * how a blueprint ends up pointing at `gpt-4o-mini ` with a trailing space, or
 * at a model the hub does not serve, and neither is discovered until a customer
 * hires the agent and it fails to run.
 *
 * Models come from the hub, which is the only thing that actually knows what it
 * will accept. Prices come from the hub too when it reports them, and otherwise
 * from KnownModelPrices — a shipped reference, clearly labelled as one.
 */
class AiModelCatalog
{
    public function __construct(
        private readonly AiAgentHubTenantService $tenantService,
    ) {}

    /**
     * @return array{providers: list<string>, models: list<array<string, mixed>>, hub_available: bool}
     */
    public function catalog(): array
    {
        [$hubModels, $hubAvailable] = $this->hubModels();

        $priced = AiModelPrice::query()
            ->get()
            ->keyBy(fn (AiModelPrice $p) => strtoupper($p->provider) . '|' . strtolower($p->model));

        $models = [];

        foreach ($hubModels as $entry) {
            $provider = strtoupper((string) ($entry['provider'] ?? ''));
            $id = (string) ($entry['id'] ?? '');

            if ($provider === '' || $id === '') {
                continue;
            }

            $suggestion = $this->suggestionFor($provider, $id, $entry);

            $models[] = [
                'provider' => $provider,
                'id' => $id,
                'name' => $entry['name'] ?? $id,
                'input_usd_per_1m' => $suggestion['input'] ?? null,
                'output_usd_per_1m' => $suggestion['output'] ?? null,
                // Where the suggested figure came from, so the form can say
                // "confirm this" for a shipped reference and stay quiet for one
                // the hub reported itself.
                'price_source' => $suggestion['source'] ?? null,
                'priced' => $priced->has($provider . '|' . strtolower($id)),
            ];
        }

        return [
            // Providers the platform can actually rent out come first: a model
            // whose provider has no pool key behind it cannot be sold, however
            // well the hub knows it.
            'providers' => $this->providers($models),
            'models' => $models,
            // Told to the form rather than hidden: an empty dropdown because the
            // hub is unreachable and an empty dropdown because nothing is
            // configured need different reactions.
            'hub_available' => $hubAvailable,
        ];
    }

    /**
     * Models as the hub reports them, flattened.
     *
     * The hub answers either a flat list or an object keyed by provider; both
     * shapes are in the wild, so both are accepted here rather than in each
     * caller.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function hubModels(): array
    {
        try {
            $raw = $this->tenantService->listModels();
        } catch (\Throwable $e) {
            // Not fatal: the forms fall back to a free-text field, which is
            // where they started. Losing the dropdown is worse than losing the
            // page.
            Log::warning('AiModelCatalog: could not list models from the hub', ['error' => $e->getMessage()]);

            return [[], false];
        }

        $payload = $raw['data'] ?? $raw;

        if (! is_array($payload)) {
            return [[], true];
        }

        if (array_is_list($payload)) {
            return [array_values(array_filter($payload, 'is_array')), true];
        }

        $flat = [];

        foreach ($payload as $provider => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $flat[] = is_string($item)
                    ? ['provider' => $provider, 'id' => $item, 'name' => $item]
                    : array_merge(['provider' => $provider], $item, [
                        'id' => $item['id'] ?? $item['name'] ?? null,
                    ]);
            }
        }

        return [$flat, true];
    }

    /**
     * The price to pre-fill, and where it came from.
     *
     * The hub wins when it reports one — it is closer to the provider than a
     * file in this repository will ever be.
     *
     * @param  array<string, mixed>  $entry
     * @return array{input: ?float, output: ?float, source: ?string}
     */
    private function suggestionFor(string $provider, string $model, array $entry): array
    {
        $input = $entry['inputPricePerMillion'] ?? $entry['inputUsdPerMillion'] ?? null;
        $output = $entry['outputPricePerMillion'] ?? $entry['outputUsdPerMillion'] ?? null;

        if (is_numeric($input) && is_numeric($output)) {
            return ['input' => (float) $input, 'output' => (float) $output, 'source' => 'hub'];
        }

        $known = KnownModelPrices::for($provider, $model);

        return $known === null
            ? ['input' => null, 'output' => null, 'source' => null]
            : ['input' => $known['input'], 'output' => $known['output'], 'source' => 'reference'];
    }

    /**
     * @param  list<array<string, mixed>>  $models
     * @return list<string>
     */
    private function providers(array $models): array
    {
        $rentable = AiTokenPoolKey::query()->distinct()->pluck('provider')
            ->map(fn ($p) => strtoupper($p))->all();

        $rest = collect($models)->pluck('provider')
            ->merge(KnownModelPrices::providers())
            ->merge(AiModelPrice::query()->distinct()->pluck('provider')->map(fn ($p) => strtoupper($p)))
            ->unique()
            ->reject(fn ($p) => in_array($p, $rentable, true))
            ->values()
            ->all();

        return array_values(array_unique(array_merge($rentable, $rest)));
    }
}
