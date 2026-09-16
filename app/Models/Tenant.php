<?php

namespace App\Models;

use App\Services\Market\MarketDocuments;
use App\Services\Market\MarketResolver;
use Carbon\CarbonInterface;
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
        // Unlike market_code this one may be corrected later: a business can
        // move, and its country's default zone is only ever a starting guess.
        'timezone',
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

            // Copied from the market rather than read through it forever. The
            // market's zone is a starting value, not a live link: Brazil alone
            // spans four of them, so a workspace may legitimately sit somewhere
            // its country's default does not name — and correcting a market's
            // default years later must not silently move the business hours and
            // renewal dates of every workspace already inside it.
            $this->timezone ??= Market::query()
                ->whereKey($this->market_code)
                ->value('default_timezone');
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
     * The currency this workspace's money is in — its market's, fixed with it.
     *
     * One place to ask, because the answer has to be the same in all of them:
     * the prepaid balance, the invoices, the ledger and every price quoted on a
     * screen are one currency or the customer cannot reconcile any of them.
     *
     * `market_code` is NOT NULL with a foreign key, so the fallback is only
     * reachable when the relation was not loadable at all (a deleted market
     * row, a fixture built by hand). Reais rather than an exception: this is
     * called from notification and renewal paths where throwing would turn a
     * missing join into a charge that never happens.
     */
    public function currency(): string
    {
        return $this->market?->currency ?: 'BRL';
    }

    /**
     * The clock this workspace reads: its own if one was ever set, else the
     * zone its market starts everyone at.
     *
     * ⚠️ Named displayTimezone() and not timezone() on purpose. `timezone` is
     * also the column, and Eloquent resolves a missing attribute by looking for
     * a method of the same name and demanding it return a relation — so a
     * method named after a column throws a LogicException the moment the
     * attribute is absent, which is exactly what a fresh `new Tenant` is. Same
     * trap as the $connection property; currency() is only safe because
     * `currency` is not a column here.
     *
     * UTC as the last resort rather than Brazil: reaching it means both the
     * column and the market row are gone, and an obviously foreign time is far
     * easier to notice than a wrong-but-plausible local one.
     */
    public function displayTimezone(): string
    {
        return $this->timezone ?: ($this->market?->default_timezone ?: 'UTC');
    }

    /**
     * A date as this workspace's own day, not as the database's.
     *
     * Timestamps are stored in UTC, and that is not a formatting detail: a
     * period ending 02:00 UTC on the 20th is still the 19th in São Paulo and
     * already the 20th in Jakarta, so printing the raw column tells customers
     * in both countries the wrong day. Every caller is filling a {{due_date}}
     * in a notification template, which is why this returns '' and not null.
     *
     * The pattern comes from the market's language, not from the server: 05/08
     * is the fifth of August to a Brazilian reader and the eighth of May to an
     * American one, and a due date that means two things is worse than no date.
     */
    public function formatDate(?CarbonInterface $date): string
    {
        return $this->writeDate($date, 'date');
    }

    /** The same, to the minute — for "your password was changed on …". */
    public function formatDateTime(?CarbonInterface $date): string
    {
        return $this->writeDate($date, 'datetime');
    }

    private function writeDate(?CarbonInterface $date, string $shape): string
    {
        if ($date === null) {
            return '';
        }

        $locale = $this->market?->default_locale ?: config('markets.default_locale');
        $formats = config('markets.date_formats');
        $pattern = $formats[$locale][$shape]
            ?? $formats[config('markets.default_locale')][$shape]
            ?? 'd/m/Y';

        // copy() because Carbon's timezone() mutates in place: without it this
        // rewrites the caller's own model attribute as a side effect.
        return $date->copy()->timezone($this->displayTimezone())->format($pattern);
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
        // A country whose rails ask for no document has nothing to be missing.
        // Without this, a market configured that way would show "incomplete"
        // forever and its checkout would never unlock — the Brazilian rule
        // applied to somewhere it does not exist.
        if (! MarketDocuments::required($this->market_code)) {
            return true;
        }

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
