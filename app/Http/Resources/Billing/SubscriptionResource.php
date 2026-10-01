<?php

namespace App\Http\Resources\Billing;

use App\Models\Connection;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /** Whether to attach current resource usage counts (opt-in; avoids N+1 in listings). */
    protected bool $withUsage = false;

    public function withUsage(bool $value = true): static
    {
        $this->withUsage = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $entitlements = $this->entitlements();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'billing_cycle' => $this->billing_cycle,
            'price_cents' => $this->price_cents,
            // Snapshotted with the price when the workspace subscribed. Sent
            // because the dashboard used to fall back to the *plan's* currency,
            // which is the platform's home one — so a country whose plan was
            // priced separately still read its own subscription in reais.
            'currency' => $this->currency,
            'is_usable' => $this->isUsable(),
            'quotas' => $entitlements['quotas'],
            'features' => $entitlements['features'],
            $this->mergeWhen($this->withUsage, fn () => ['usage' => $this->currentUsage()]),
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'trial_ends_at' => $this->trial_ends_at,
            'grace_ends_at' => $this->grace_ends_at,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'user' => $this->tenant->relationLoaded('user') && $this->tenant->user ? [
                    'name' => $this->tenant->user->name,
                    'email' => $this->tenant->user->email,
                ] : null,
            ]),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Live usage for quota'd resources, mirroring what SubscriptionGate enforces.
     */
    protected function currentUsage(): array
    {
        $tenantId = $this->tenant_id;
        $tenant = $this->tenant;

        return [
            'connections' => Connection::where('tenant_id', $tenantId)->count(),
            'agents' => User::where('tenant_id', $tenantId)->count(),
            // Every quota a plan can carry has a meter here, read from the same
            // service that enforces it — a usage panel showing two of five
            // limits leaves the customer to discover the other three by
            // hitting them.
            'included_instances' => $tenant ? app(ApiwayService::class)->usageSummary($tenant)['included_used'] : 0,
            'included_trained_agents' => $tenant ? app(SubscriptionGate::class)->trainedAgentsUsed($tenant) : 0,
            // Bytes, against the plan's gigabytes **plus** any rented ones: the
            // limit a customer actually runs into is the sum.
            'gallery_bytes' => $tenant ? app(GalleryStorage::class)->usedBytes($tenant) : 0,
            'gallery_limit_bytes' => $tenant ? app(GalleryStorage::class)->limitBytes($tenant) : 0,
        ];
    }
}
