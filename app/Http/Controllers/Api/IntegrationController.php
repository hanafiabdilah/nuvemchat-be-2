<?php

namespace App\Http\Controllers\Api;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlowPaymentResource;
use App\Http\Resources\IntegrationResource;
use App\Models\Integration;
use App\Services\Integrations\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The workspace's external apps: payment gateways and pixels.
 *
 * Every write that reaches a provider answers a refusal with our own sentence
 * (UpstreamServiceException) and a 422, never the provider's 401 — the SPA
 * signs the user out on any 401, and "your OpenPix key is wrong" must not look
 * like "your session expired".
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrations,
    ) {}

    /**
     * The connected accounts, plus the catalog of what can be connected.
     *
     * One call because the page cannot draw either half without the other: a
     * card is a catalog entry decorated with the accounts behind it. The flow
     * builder calls the same endpoint (with `?category=`) to fill its pickers.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $query = Integration::forTenant($tenantId)->orderBy('name');

        if ($category = IntegrationCategory::tryFrom((string) $request->query('category'))) {
            $query->inCategory($category);
        }

        $usage = $this->integrations->usageByIntegration($tenantId);

        $integrations = $query->get()->each(
            fn (Integration $integration) => $integration->setRelation('usedByFlows', collect($usage[$integration->id] ?? [])),
        );

        return response()->json([
            'data' => IntegrationResource::collection($integrations),
            'catalog' => array_map(fn (IntegrationProvider $provider) => $provider->toCatalog(), IntegrationProvider::cases()),
            'categories' => array_map(fn (IntegrationCategory $category) => [
                'key' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
            ], IntegrationCategory::cases()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $provider = IntegrationProvider::tryFrom((string) $request->input('provider'));

        if ($provider === null) {
            throw ValidationException::withMessages(['provider' => ['Escolha um aplicativo da lista.']]);
        }

        $validated = $request->validate($this->rules($provider, creating: true), [], $this->attributes($provider));

        try {
            $integration = $this->integrations->create((int) $request->user()->tenant_id, $provider, $validated);
        } catch (UpstreamServiceException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'message' => 'Integração conectada.',
            'data' => new IntegrationResource($integration->setRelation('usedByFlows', collect())),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $integration = $this->find($request, $id);
        $validated = $request->validate($this->rules($integration->provider, creating: false), [], $this->attributes($integration->provider));

        try {
            $integration = $this->integrations->update($integration, $validated);
        } catch (UpstreamServiceException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'message' => 'Integração atualizada.',
            'data' => new IntegrationResource($this->withUsage($integration)),
        ]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $integration = $this->find($request, $id);

        try {
            $integration = $this->integrations->test($integration);
        } catch (UpstreamServiceException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'message' => 'Conexão verificada.',
            'data' => new IntegrationResource($this->withUsage($integration)),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->integrations->delete($this->find($request, $id));

        return response()->json(['message' => 'Integração removida.']);
    }

    /**
     * The charges flows issued through this account, newest first, with a
     * 30-day summary — "did anyone pay through the bot this month" should not
     * need the gateway's own dashboard.
     */
    public function payments(Request $request, int $id): JsonResponse
    {
        $integration = $this->find($request, $id);

        $payments = $integration->payments()
            ->with(['contact:id,name', 'flow:id,name'])
            ->latest('id')
            ->limit(30)
            ->get();

        $recent = $integration->payments()->where('created_at', '>=', now()->subDays(30));

        return response()->json([
            'data' => FlowPaymentResource::collection($payments),
            'summary' => [
                'paid_count' => (clone $recent)->where('status', FlowPaymentStatus::Paid)->count(),
                'paid_cents' => (int) (clone $recent)->where('status', FlowPaymentStatus::Paid)->sum('amount_cents'),
                'pending_count' => (clone $recent)->where('status', FlowPaymentStatus::Pending)->count(),
                'total_count' => (clone $recent)->count(),
            ],
        ]);
    }

    private function find(Request $request, int $id): Integration
    {
        return Integration::forTenant((int) $request->user()->tenant_id)->findOrFail($id);
    }

    private function withUsage(Integration $integration): Integration
    {
        $usage = $this->integrations->usageByIntegration((int) $integration->tenant_id);

        return $integration->setRelation('usedByFlows', collect($usage[$integration->id] ?? []));
    }

    /**
     * Rules derived from the provider's own field list, so the form and the
     * validation cannot disagree about what a field is called or requires.
     *
     * On update a blank secret is allowed and means "keep the stored one".
     *
     * @return array<string, mixed>
     */
    private function rules(IntegrationProvider $provider, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ];

        foreach ($provider->fields() as $field) {
            $prefix = $field['secret'] ? 'credentials' : 'settings';

            $presence = match (true) {
                $field['secret'] && $field['required'] && $creating => ['required'],
                $field['secret'] => ['nullable'],
                $field['required'] && $creating => ['required'],
                $field['required'] => ['sometimes', 'required'],
                default => ['nullable'],
            };

            $rules["{$prefix}.{$field['key']}"] = array_merge($presence, $field['rules']);
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function attributes(IntegrationProvider $provider): array
    {
        $attributes = [];

        foreach ($provider->fields() as $field) {
            $prefix = $field['secret'] ? 'credentials' : 'settings';
            $attributes["{$prefix}.{$field['key']}"] = $field['label'];
        }

        return $attributes;
    }
}
