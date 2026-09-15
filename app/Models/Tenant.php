<?php

namespace App\Models;

use App\Services\Market\MarketResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;

class Tenant extends Model
{
    protected $fillable = [
        'user_id',
        'current_subscription_id',
        'lead_settings',
        'entitlement_overrides',
        'audio_dictionary',
        'billing_name',
        'billing_document_type',
        'billing_document_number',
    ];

    protected $casts = [
        'lead_settings' => 'array',
        'entitlement_overrides' => 'array',
        'audio_dictionary' => 'array',
    ];

    /**
     * A workspace's market is decided once, when it is created, and never
     * again: its balance, invoices and ledger are amounts in that market's
     * currency, and moving it would relabel the money rather than convert it.
     *
     * Filled here rather than in the signup controller because workspaces are
     * created in more places than signup — seeders, tests, the next onboarding
     * path — and a workspace's country must not depend on someone remembering.
     * Deliberately not in $fillable: the domain decides, never a request body.
     *
     * Enforced in save() rather than in model events: Event::fake() silences
     * model events (39 test files use it) and saveQuietly() skips them in
     * production code, and neither may produce a workspace without a country
     * or one that moves between countries. A query-builder mass update still
     * bypasses this; nothing should issue one.
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            $this->market_code ??= MarketResolver::codeForRequest();
        } elseif ($this->isDirty('market_code')) {
            throw new LogicException(
                "Workspace {$this->id} belongs to market {$this->getOriginal('market_code')}; a workspace never changes market."
            );
        }

        return parent::save($options);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_code', 'code');
    }

    /**
     * Whether this workspace can be charged at all.
     *
     * The acquirer refuses a Pix or a boleto without a CPF or CNPJ, so the
     * payment service demands one on every charge — which makes this the
     * difference between a renewal that runs unattended and one that cannot.
     * Checked before a scheduler tries rather than after it fails.
     */
    public function hasBillingIdentity(): bool
    {
        return filled($this->billing_document_number) && filled($this->billing_document_type);
    }

    /**
     * How this workspace is addressed at the payment service. Ours, so we never
     * have to store their identifiers to bill somebody — and stable, because a
     * retried signup must converge on one person rather than two.
     */
    public function paymentCustomerReference(): string
    {
        return "tenant:{$this->id}";
    }

    /**
     * The override block if one is in force, or null.
     *
     * Expiry is checked on read rather than by clearing the column, so the
     * record of what was granted and until when survives the grant itself.
     */
    public function activeEntitlementOverrides(): ?array
    {
        $overrides = $this->entitlement_overrides;

        if (! is_array($overrides) || $overrides === []) {
            return null;
        }

        $expiresAt = $overrides['expires_at'] ?? null;

        if ($expiresAt !== null && \Illuminate\Support\Carbon::parse($expiresAt)->isPast()) {
            return null;
        }

        return $overrides;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner to reach for platform notifications, or null when there is no
     * verified WhatsApp number — legacy accounts predating verification and
     * Fortify-created users have none. Callers skip rather than fail.
     */
    public function notifiableOwner(): ?User
    {
        $user = $this->user;

        return $user && $user->whatsapp_verified_at && filled($user->whatsapp_number) ? $user : null;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The tenant's current subscription (denormalised pointer for O(1) lookup).
     */
    public function currentSubscription()
    {
        return $this->belongsTo(Subscription::class, 'current_subscription_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function apiwaySubscriptions()
    {
        return $this->hasMany(ApiwaySubscription::class);
    }

    public function apiwayInstances()
    {
        return $this->hasMany(ApiwayInstance::class);
    }

    public function trainedAgentHires()
    {
        return $this->hasMany(TrainedAgentHire::class);
    }

    /** Virtual numbers rented from API Way (SMS / OTP reception). */
    public function virtualNumbers()
    {
        return $this->hasMany(VirtualNumber::class);
    }

    public function galleryAssets()
    {
        return $this->hasMany(GalleryAsset::class);
    }

    /** The extra gallery storage this workspace rents, if any. */
    public function galleryStorageRental()
    {
        return $this->hasOne(GalleryStorageRental::class);
    }

    /** External apps (payment gateways, pixels) this workspace connected. */
    public function integrations()
    {
        return $this->hasMany(Integration::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * All conversations belonging to this tenant, reached through its
     * connections (conversations have no direct tenant_id).
     */
    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(Conversation::class, Connection::class);
    }

    public function quickMessages()
    {
        return $this->hasMany(QuickMessage::class);
    }

    public function aiHubTenant()
    {
        return $this->hasOne(AiHubTenant::class);
    }
}
