<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookEvents;
use App\Services\Webhooks\WebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Outbound webhooks, from the dashboard (Developer › Webhooks).
 *
 * The secret is returned twice in an endpoint's life: when it is created and
 * when it is rotated. Every other response carries only its hint.
 */
class WebhookController extends Controller
{
    public function __construct(
        private WebhookDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::where('tenant_id', $request->user()->tenant_id)->latest('id')->get();

        return response()->json([
            'data' => WebhookEndpointResource::collection($endpoints),
            'events' => WebhookEvents::SUBSCRIBABLE,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));
        $user = $request->user();

        $this->assertUrl($data['url']);

        if (WebhookEndpoint::where('tenant_id', $user->tenant_id)->count() >= WebhookEndpoint::MAX_PER_TENANT) {
            throw new PublicApiException(
                'Esta conta já tem '.WebhookEndpoint::MAX_PER_TENANT.' webhooks. Remova um que não é mais usado para criar outro.',
                'webhook_limit',
            );
        }

        $endpoint = WebhookEndpoint::create([
            'tenant_id' => $user->tenant_id,
            'url' => trim($data['url']),
            'events' => array_values(array_unique($data['events'])),
            'secret' => WebhookEndpoint::newSecret(),
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        Log::info('Webhook endpoint created', ['tenant_id' => $user->tenant_id, 'endpoint_id' => $endpoint->id, 'actor_id' => $user->id]);

        return (new WebhookEndpointResource($endpoint))
            ->additional(['secret' => $endpoint->secret])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id): WebhookEndpointResource
    {
        $endpoint = $this->find($request, $id);
        $data = $request->validate($this->rules(required: false) + ['is_active' => ['sometimes', 'boolean']]);

        if (isset($data['url'])) {
            $this->assertUrl($data['url']);
            $data['url'] = trim($data['url']);
        }

        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        $endpoint->update($data);

        return new WebhookEndpointResource($endpoint);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Webhook removed']);
    }

    /** A new secret; the old one stops being used on the very next delivery. */
    public function rotateSecret(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $endpoint->forceFill(['secret' => WebhookEndpoint::newSecret()])->save();

        Log::info('Webhook secret rotated', ['endpoint_id' => $endpoint->id, 'actor_id' => $request->user()->id]);

        return (new WebhookEndpointResource($endpoint))
            ->additional(['secret' => $endpoint->secret])
            ->response();
    }

    public function test(Request $request, int $id): WebhookDeliveryResource
    {
        return new WebhookDeliveryResource($this->dispatcher->ping($this->find($request, $id)));
    }

    public function deliveries(Request $request, int $id)
    {
        $endpoint = $this->find($request, $id);

        return WebhookDeliveryResource::collection($endpoint->deliveries()->latest('id')->limit(50)->get());
    }

    /** @return array<string, mixed> */
    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'url' => [$presence, 'string', 'max:2048'],
            'events' => [$presence, 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvents::SUBSCRIBABLE)],
        ];
    }

    private function assertUrl(string $url): void
    {
        if ($problem = WebhookUrl::problem($url)) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
    }

    private function find(Request $request, int $id): WebhookEndpoint
    {
        return WebhookEndpoint::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
