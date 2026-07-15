<?php

namespace App\Http\Resources\Billing;

use App\Models\AiHubRun;
use App\Models\Connection;
use App\Models\User;
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
            'quantity' => $this->quantity,
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
     * AI runs are counted within the current billing period.
     */
    protected function currentUsage(): array
    {
        $tenantId = $this->tenant_id;

        return [
            'connections' => Connection::where('tenant_id', $tenantId)->count(),
            'agents' => User::where('tenant_id', $tenantId)->count(),
            'ai_runs' => AiHubRun::where('tenant_id', $tenantId)
                ->when($this->current_period_start, fn ($q) => $q->where('created_at', '>=', $this->current_period_start))
                ->when($this->current_period_end, fn ($q) => $q->where('created_at', '<=', $this->current_period_end))
                ->count(),
        ];
    }
}
