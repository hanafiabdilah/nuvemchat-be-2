<?php

namespace App\Models;

use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One external account a workspace connected: an OpenPix key, a Mercado Pago
 * token, a pixel.
 *
 * `credentials` never leaves this model in the clear — it is `$hidden`, and the
 * resource sends a masked preview instead. The raw value is read in exactly one
 * kind of place: the driver that calls the provider with it.
 */
class Integration extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider',
        'name',
        'credentials',
        'settings',
        'meta',
        'enabled',
        'webhook_token',
        'verified_at',
        'last_used_at',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'provider' => IntegrationProvider::class,
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'meta' => 'array',
        'enabled' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $hidden = ['credentials', 'webhook_token'];

    protected static function booted(): void
    {
        static::creating(function (Integration $integration) {
            if (empty($integration->webhook_token)) {
                $integration->webhook_token = Str::random(48);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments()
    {
        return $this->hasMany(FlowPayment::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeInCategory(Builder $query, IntegrationCategory $category): Builder
    {
        return $query->whereIn('provider', IntegrationProvider::valuesFor($category));
    }

    public function category(): IntegrationCategory
    {
        return $this->provider->category();
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    /**
     * Where the provider should call us about this account's payments.
     *
     * Only payment gateways have one: a pixel is fire-and-forget and nobody
     * ever calls back about it.
     */
    public function webhookUrl(): ?string
    {
        if ($this->category() !== IntegrationCategory::Payment) {
            return null;
        }

        return route('webhook.integrations', [
            'provider' => $this->provider->value,
            'token' => $this->webhook_token,
        ]);
    }

    /**
     * Remember the last thing that went wrong, in our words.
     *
     * Kept on the row because the failures that matter here happen with nobody
     * watching — a pixel event sent from a queue at 3am, a charge created by a
     * bot — and the Integrations page is where somebody finally looks.
     */
    public function recordError(string $message): void
    {
        $this->forceFill([
            'last_error' => Str::limit($message, 480),
            'last_error_at' => now(),
        ])->saveQuietly();
    }

    public function recordUse(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->saveQuietly();
    }
}
