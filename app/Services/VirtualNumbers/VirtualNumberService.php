<?php

namespace App\Services\VirtualNumbers;

use App\Enums\Credit\CreditTransactionType;
use App\Enums\Notification\NotificationType;
use App\Enums\Numbers\VirtualNumberStatus;
use App\Events\VirtualNumberSmsReceived;
use App\Events\VirtualNumberUpdated;
use App\Exceptions\ApiwayNumbersException;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Models\Tenant;
use App\Models\VirtualNumber;
use App\Models\VirtualNumberMessage;
use App\Services\Billing\BillingNotifier;
use App\Services\Credits\CreditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Renting virtual numbers from API Way and reselling them to tenants.
 *
 * The shape of this offering comes from two facts that are easy to miss:
 *
 * 1. **A number is a monthly subscription, not a single OTP.** API Way bills
 *    the platform every month until the number is deleted, so cancelling is not
 *    housekeeping — it is the only thing that stops a cost. A tenant who gets
 *    their code in ten seconds still paid for the month, and we still owe API
 *    Way for it, which is why cancelling refunds nothing.
 * 2. **There is no upstream renew call.** The subscription renews itself. So
 *    `renews_at` is a deadline on our side: by then the tenant has been charged
 *    for the next month, or the number must be cancelled before API Way bills
 *    us for a month nobody paid for.
 *
 * Money moves before the number exists, the same rule the API Way instances
 * follow, and for the same reason: a failure gives it straight back to the
 * balance, which is a ledger row rather than a refund somebody has to remember.
 */
class VirtualNumberService
{
    private const CATALOG_CACHE_KEY = 'apiway-numbers:catalog';

    private const CATALOG_CACHE_TTL = 300;

    /**
     * How long a purchase may sit unconfirmed before `numbers:sync` decides
     * nobody is coming back for it. Generous on purpose: the row is only ever
     * here after an ambiguous timeout, and the wrong call is refunding a
     * purchase whose number does exist upstream.
     */
    public const STALLED_PURCHASE_MINUTES = 30;

    public function __construct(
        protected ApiwayNumbersClient $client,
        protected CreditService $credits,
        protected BillingNotifier $notifier,
    ) {}

    /** Ledger reference for the first month of a number. */
    public static function purchaseReference(VirtualNumber $row): string
    {
        return "numbers:buy:{$row->id}";
    }

    /**
     * Ledger reference for one renewal.
     *
     * Carries the date it is paying to move past, because that is what makes it
     * unique: the same number renews again next month, and a reference naming
     * only the number would have the ledger refuse the second charge as a
     * duplicate of the first. It also makes the daily pass safe to repeat — a
     * second attempt for the same cycle is swallowed rather than charged.
     */
    public static function renewalReference(VirtualNumber $row): string
    {
        return "numbers:renew:{$row->id}:".($row->renews_at?->toDateString() ?? 'none');
    }

    // --- Catalog -----------------------------------------------------------

    /** Raw upstream catalog (apps, DDD → city, our monthly cost). */
    public function catalog(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CATALOG_CACHE_KEY);
        }

        return Cache::remember(self::CATALOG_CACHE_KEY, self::CATALOG_CACHE_TTL, fn () => $this->client->catalog());
    }

    /**
     * The catalog as a tenant may see it: sale prices, no costs.
     *
     * What the platform pays API Way is not the customer's business, and a page
     * that ships it in a JSON payload has published the margin whether or not it
     * renders it.
     *
     * @return array{apps: list<array{id: string, label: string, price_cents: int}>, regions: array<string, string>, currency: string}
     */
    public function tenantCatalog(): array
    {
        $catalog = $this->catalog();
        $costCents = (int) ($catalog['price_cents'] ?? 0);

        $apps = [];

        foreach (($catalog['apps'] ?? []) as $app) {
            $id = (string) ($app['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $apps[] = [
                'id' => $id,
                'label' => (string) ($app['label'] ?? $id),
                'price_cents' => NumberPricing::saleCents($id, $costCents),
            ];
        }

        return [
            'apps' => $apps,
            'regions' => $catalog['regions'] ?? [],
            'currency' => $catalog['currency'] ?? 'BRL',
        ];
    }

    /** What one month of this app costs the tenant, priced from the live catalog. */
    public function priceFor(string $app): int
    {
        return NumberPricing::saleCents($app, (int) ($this->catalog()['price_cents'] ?? 0));
    }

    // --- Purchase ----------------------------------------------------------

    /**
     * Rent a number for one month, paid from the prepaid balance.
     *
     * Charged before API Way is called. That order is only defensible because
     * every failure path below puts the money straight back — see
     * `refundUndelivered()` — and it is what keeps the invariant the rest of the
     * platform relies on: nothing is provisioned that has not been paid for.
     *
     * @throws InsufficientCreditException  balance will not cover the month
     * @throws ValidationException          app/DDD not in the catalog
     * @throws ApiwayNumbersException       upstream refused or is unreachable
     */
    public function purchase(Tenant $tenant, string $ddd, string $app): VirtualNumber
    {
        $catalog = $this->catalog();
        $this->assertInCatalog($catalog, $ddd, $app);

        $costCents = (int) ($catalog['price_cents'] ?? 0);
        $priceCents = NumberPricing::saleCents($app, $costCents);

        if ($priceCents <= 0) {
            throw ValidationException::withMessages([
                'app' => 'Este número está sem preço definido. Fale com o suporte.',
            ]);
        }

        // Checked here as well as inside the debit's lock. This one exists so an
        // unaffordable attempt leaves no row behind; the one under the lock is
        // the gate two simultaneous purchases cannot both slip past.
        if (! $this->credits->canAfford($tenant, $priceCents)) {
            throw new InsufficientCreditException($this->credits->balanceCents($tenant), $priceCents);
        }

        $row = $tenant->virtualNumbers()->create([
            'app' => $app,
            'ddd' => $ddd,
            'region' => $catalog['regions'][$ddd] ?? null,
            'status' => VirtualNumberStatus::Pending,
            'cost_cents' => $costCents,
            'price_cents' => $priceCents,
            'currency' => $catalog['currency'] ?? 'BRL',
        ]);

        try {
            $this->credits->debit(
                $tenant,
                $priceCents,
                CreditTransactionType::Purchase,
                self::purchaseReference($row),
                "Número virtual — {$this->appLabel($catalog, $app)} (DDD {$ddd})",
                ['virtual_number_id' => $row->id, 'app' => $app, 'ddd' => $ddd],
            );
        } catch (InsufficientCreditException $e) {
            // Lost the race with a concurrent purchase. Nothing was charged, so
            // the row must not survive looking like a number being activated.
            $row->delete();

            throw $e;
        }

        try {
            $data = $this->client->createNumber($ddd, $app, $this->partnerCustomerId($tenant));
        } catch (ApiwayNumbersException $e) {
            if ($e->isRetriable()) {
                // Ambiguous: a 5xx can mean the number was never created, or
                // that it was and the answer was lost. Settling it needs the
                // account's inventory, which is one cheap read away — so ask
                // now rather than leaving the customer paid-up and empty-handed
                // until the hourly sync gets to them.
                if ($settled = $this->settleUnconfirmed($row, $e)) {
                    throw $settled;
                }

                // The number did exist: the purchase stands, late but whole.
                return $row->fresh();
            }

            $this->refundUndelivered($row, $e->getErrorCode(), $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            if ($settled = $this->settleUnconfirmed($row, $e)) {
                throw $settled;
            }

            return $row->fresh();
        }

        $this->applyRemote($row, $data);
        $row->forceFill(['purchased_at' => now()])->save();

        $row = $row->fresh();
        broadcast(new VirtualNumberUpdated($row));

        return $row;
    }

    /**
     * A purchase we cannot prove either way. Left `pending` with the reason on
     * it; `numbers:sync` either finds the number upstream and adopts it, or
     * gives the money back once the wait has gone on long enough.
     */
    /**
     * Write down what happened, before trying to resolve it.
     *
     * The status and the upstream code go on the row, not only into the log,
     * because the log is the first thing to disappear: production keeps
     * `storage/logs` inside the container, so a deploy takes the day's lines
     * with it — and the two rows this was written for survived exactly that,
     * leaving a row that could only say "unavailable". A charge with nothing
     * delivered has to be able to explain itself from its own record.
     */
    protected function parkUnconfirmed(VirtualNumber $row, \Throwable $e): void
    {
        $upstream = $e instanceof ApiwayNumbersException ? $e : null;

        $meta = $row->meta ?? [];
        $meta['unconfirmed'] = array_filter([
            'message' => $e->getMessage(),
            'code' => $upstream?->getErrorCode(),
            'status' => $upstream?->getHttpStatus(),
            'at' => now()->toISOString(),
        ], fn ($value) => $value !== null);

        $row->update(['meta' => $meta]);

        Log::warning('Virtual number purchase left unconfirmed', [
            'virtual_number_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'status' => $upstream?->getHttpStatus(),
            'code' => $upstream?->getErrorCode(),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Decide, now, what an ambiguous purchase failure means.
     *
     * One read of the account inventory answers the only question that matters:
     * did the number get created? If it did, the customer gets what they paid
     * for immediately. If it did not, the money goes back immediately. Only when
     * that read *also* fails is there genuinely nothing to conclude, and the row
     * waits for `numbers:sync`.
     *
     * @return \Throwable|null what the caller should raise, or null when the
     *                          number turned out to exist and the purchase
     *                          stands. A returned exception is not always the
     *                          one passed in: once the charge is back,
     *                          "unavailable" and "unavailable, and your money is
     *                          back" are different things to be told by a page
     *                          showing a debited balance.
     */
    protected function settleUnconfirmed(VirtualNumber $row, \Throwable $e): ?\Throwable
    {
        $this->parkUnconfirmed($row, $e);

        try {
            $remote = $this->remoteNumbers();
        } catch (\Throwable $probe) {
            Log::warning('Could not check the API Way inventory after a failed purchase', [
                'virtual_number_id' => $row->id,
                'error' => $probe->getMessage(),
            ]);

            return $e;
        }

        $claimed = $this->claimedProviderIds();
        $match = $this->matchRemoteFor($row, $remote, $claimed);

        if ($match !== null) {
            $this->adoptRemote($row, $match);

            Log::info('A failed purchase turned out to exist upstream and was adopted', [
                'virtual_number_id' => $row->id,
                'provider_number_id' => $match['id'] ?? null,
            ]);

            // Nothing to raise: the customer has the number they paid for.
            return null;
        }

        $upstream = $e instanceof ApiwayNumbersException ? $e : null;
        $this->refundUndelivered($row, $upstream?->getErrorCode() ?? 'unconfirmed', $e->getMessage(), $upstream?->getHttpStatus());

        return new ApiwayNumbersException(
            'Não foi possível contratar o número agora. O valor foi devolvido ao seu saldo — tente novamente em alguns minutos.',
            ApiwayNumbersException::PURCHASE_REVERSED,
            $upstream?->getHttpStatus() ?? 502,
        );
    }

    /** Every number on the account, keyed by provider id. */
    protected function remoteNumbers(): \Illuminate\Support\Collection
    {
        return collect($this->client->numbers())
            ->filter(fn ($item) => is_array($item) && isset($item['id']))
            ->keyBy(fn ($item) => (int) $item['id']);
    }

    /**
     * @return list<int>
     */
    protected function claimedProviderIds(): array
    {
        return VirtualNumber::query()
            ->whereNotNull('provider_number_id')
            ->pluck('provider_number_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The upstream number that belongs to this unconfirmed purchase, if any.
     *
     * Matched on the partner reference (the only field tying a number back to
     * the workspace that paid) plus app and DDD. A number already claimed by
     * another row is never a candidate.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $remote
     * @param  list<int>  $claimed
     */
    protected function matchRemoteFor(VirtualNumber $row, \Illuminate\Support\Collection $remote, array $claimed): ?array
    {
        return $remote->first(function ($data, $id) use ($row, $claimed) {
            if (in_array((int) $id, $claimed, true)) {
                return false;
            }

            $reference = $data['partner_customer_id'] ?? null;

            if ($reference !== null && $reference !== $this->partnerCustomerId($row->tenant)) {
                return false;
            }

            return ($data['app'] ?? null) === $row->app && (string) ($data['ddd'] ?? '') === $row->ddd;
        });
    }

    /** Hand an upstream number to the purchase that was waiting for it. */
    protected function adoptRemote(VirtualNumber $row, array $data): void
    {
        $this->applyRemote($row, $data);
        $row->forceFill(['purchased_at' => $row->purchased_at ?? now()])->save();

        $meta = $row->meta ?? [];
        unset($meta['unconfirmed']);
        $meta['adopted_at'] = now()->toISOString();
        $row->update(['meta' => $meta]);

        broadcast(new VirtualNumberUpdated($row->fresh()));
    }

    /**
     * Give back a month that was charged and never delivered.
     *
     * The money never left the platform, so this is a ledger row rather than a
     * refund somebody has to make by hand — which is the entire reason charging
     * before provisioning is acceptable on this surface.
     */
    public function refundUndelivered(VirtualNumber $row, ?string $code, ?string $reason, ?int $status = null): void
    {
        $reversal = $this->credits->reverseByReference(
            $row->tenant,
            self::purchaseReference($row),
            'Devolução — número virtual não ativado',
            ['virtual_number_id' => $row->id, 'reason' => $code],
        );

        $meta = $row->meta ?? [];
        // Resolved, so the "waiting to find out" note goes — but its diagnosis
        // moves across rather than being dropped. This row is the only account
        // of a charge with nothing delivered once the day's logs are gone.
        $status ??= $meta['unconfirmed']['status'] ?? null;
        unset($meta['unconfirmed']);
        $meta['failure'] = array_filter([
            'code' => $code,
            'message' => $reason,
            'status' => $status,
            'at' => now()->toISOString(),
        ], fn ($value) => $value !== null);

        if ($reversal !== null) {
            $meta['refunded_to_balance_at'] = now()->toISOString();
            $meta['refunded_cents'] = abs((int) $reversal->amount_cents);
        }

        $row->update(['status' => VirtualNumberStatus::Failed, 'meta' => $meta]);

        Log::error('Virtual number purchase failed', [
            'virtual_number_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'code' => $code,
            'status' => $status,
            'refunded_to_balance' => $reversal !== null,
        ]);

        broadcast(new VirtualNumberUpdated($row->fresh()));

        if ($reversal !== null) {
            $this->notifier->notifyTenant(NotificationType::VirtualNumberRefunded, $row->tenant, [
                'amount' => $this->money(abs((int) $reversal->amount_cents)),
            ]);
        }
    }

    // --- Renewal -----------------------------------------------------------

    /**
     * Charge the next month to the balance.
     *
     * Re-priced from the live catalog first: API Way renews at whatever their
     * price is that day, and charging last month's amount would quietly move the
     * difference onto the platform. The customer is told three days ahead, which
     * is what makes a price that can move fair rather than a surprise.
     *
     * @return bool false when the balance will not cover it — the caller decides
     *              what that means, because it means different things to a
     *              scheduled pass and to a person who just clicked renew.
     */
    public function chargeRenewal(VirtualNumber $row): bool
    {
        $costCents = (int) ($this->catalog()['price_cents'] ?? $row->cost_cents);
        $priceCents = NumberPricing::saleCents($row->app, $costCents);

        if ($priceCents <= 0) {
            $priceCents = $row->price_cents;
        }

        $row->update(['cost_cents' => $costCents, 'price_cents' => $priceCents]);
        $row->refresh();

        try {
            // A null return means this cycle was already charged — a pass that
            // ran twice. Reported as success, because it is: the month is paid.
            $this->credits->debit(
                $row->tenant,
                $priceCents,
                CreditTransactionType::Renewal,
                self::renewalReference($row),
                "Renovação de número virtual — {$row->msisdn}",
                ['virtual_number_id' => $row->id, 'renews_at' => $row->renews_at?->toDateString()],
            );
        } catch (InsufficientCreditException) {
            return false;
        }

        $meta = $row->meta ?? [];
        $meta['last_renewal_charged_for'] = $row->renews_at?->toDateString();
        $row->update(['meta' => $meta, 'renewal_reminder_sent_at' => null]);

        broadcast(new VirtualNumberUpdated($row->fresh()));

        return true;
    }

    // --- Cancel ------------------------------------------------------------

    /**
     * End the number upstream and stop the monthly charge.
     *
     * No refund, and the UI has to say so before the click: the month is already
     * paid to API Way, and cancelling on day two returns nothing. `$reason` is
     * how the tenant later learns why a number they did not cancel is gone.
     */
    public function cancel(VirtualNumber $row, string $reason = 'requested'): VirtualNumber
    {
        if ($row->status->isTerminal()) {
            return $row;
        }

        // ⚠️ A purchase that never produced a number is not a cancellation, it
        // is a refund. "The month is already paid upstream" is what makes
        // cancelling non-refundable — and for a row with no provider id we owe
        // API Way nothing, because nothing was ever created. Left as an
        // ordinary cancel it also became unreachable: `cancelled` is terminal,
        // so numbers:sync stops looking, and the charge is stranded for good.
        // (Two production purchases were lost exactly this way.)
        if ($row->provider_number_id === null) {
            return $this->cancelUndelivered($row);
        }

        if ($row->provider_number_id) {
            try {
                $this->client->cancelNumber($row->provider_number_id);
            } catch (ApiwayNumbersException $e) {
                // Already gone upstream is the outcome we wanted. Anything else
                // has to surface: a cancel that silently failed leaves the
                // platform paying for it every month.
                if ($e->getErrorCode() !== ApiwayNumbersException::NOT_FOUND) {
                    throw $e;
                }
            }
        }

        $meta = $row->meta ?? [];
        $meta['cancel_reason'] = $reason;

        $row->update([
            'status' => VirtualNumberStatus::Cancelled,
            'cancelled_at' => now(),
            'meta' => $meta,
        ]);

        $row = $row->fresh();
        broadcast(new VirtualNumberUpdated($row));

        return $row;
    }

    /**
     * Cancel a purchase that never confirmed.
     *
     * The inventory decides, exactly as it does after a failed create: if the
     * number does exist, it is handed over first and then cancelled properly —
     * refunding it would leave the platform paying for a number nobody owns.
     * If it does not exist, the charge goes back.
     *
     * When the inventory cannot be read, nothing is concluded and the row stays
     * `pending` for numbers:sync. Refusing the click is worse UX than a wrong
     * refund only if you ignore that the wrong refund is unrecoverable.
     */
    protected function cancelUndelivered(VirtualNumber $row): VirtualNumber
    {
        try {
            $match = $this->matchRemoteFor($row, $this->remoteNumbers(), $this->claimedProviderIds());
        } catch (\Throwable $e) {
            Log::warning('Could not check the API Way inventory while cancelling an unconfirmed purchase', [
                'virtual_number_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            throw new ApiwayNumbersException(
                'Não foi possível concluir agora. Este número ainda não foi confirmado pela API Way — deixe-o como está e a cobrança será resolvida automaticamente em até uma hora.',
                ApiwayNumbersException::UPSTREAM_UNAVAILABLE,
                502,
            );
        }

        if ($match !== null) {
            // It existed after all: take ownership, then cancel it for real.
            $this->adoptRemote($row, $match);

            return $this->cancel($row->fresh(), 'requested');
        }

        $this->refundUndelivered($row, 'cancelled_before_delivery', 'Cancelado antes de a API Way confirmar o número.');

        return $row->fresh();
    }

    // --- Incoming SMS ------------------------------------------------------

    /**
     * Store one SMS and tell the open dashboard about it.
     *
     * Shared by the webhook and the poll, so an OTP that arrives by both routes
     * is stored once — the unique dedupe key decides, not the caller.
     *
     * @param  array{from?: string|null, message?: string|null, code?: string|null, received_at?: string|null, id?: int|null}  $payload
     * @return VirtualNumberMessage|null null when it was already stored
     */
    public function recordMessage(VirtualNumber $row, array $payload): ?VirtualNumberMessage
    {
        $sender = $this->trimOrNull($payload['from'] ?? null, 120);
        $body = $this->trimOrNull($payload['message'] ?? null);
        $code = $this->trimOrNull($payload['code'] ?? null, 32);
        $receivedRaw = $payload['received_at'] ?? null;

        $dedupeKey = VirtualNumberMessage::dedupeKeyFor($sender, $body, is_string($receivedRaw) ? $receivedRaw : null);

        if (VirtualNumberMessage::where('virtual_number_id', $row->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $receivedAt = $receivedRaw ? Carbon::parse($receivedRaw) : now();

        $message = VirtualNumberMessage::create([
            'virtual_number_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'provider_message_id' => isset($payload['id']) && is_numeric($payload['id']) ? (int) $payload['id'] : null,
            'sender' => $sender,
            'body' => $body,
            'code' => $code,
            'received_at' => $receivedAt,
            'dedupe_key' => $dedupeKey,
        ]);

        // Only ever forward: a poll that returns an old message must not drag
        // the list's "last activity" backwards.
        if ($row->last_message_at === null || $receivedAt->gt($row->last_message_at)) {
            $row->forceFill(['last_message_at' => $receivedAt])->save();
        }

        broadcast(new VirtualNumberSmsReceived($message->load('number')));

        return $message;
    }

    /**
     * Pull whatever API Way has for this number.
     *
     * The fallback for the webhook, and the button behind "refresh" on the
     * number's screen — a person watching for a code will always press it, and
     * the poll is what makes that press mean something.
     *
     * @return int how many messages were new
     */
    public function pullMessages(VirtualNumber $row): int
    {
        if (! $row->provider_number_id) {
            return 0;
        }

        $stored = 0;

        foreach ($this->client->sms($row->provider_number_id) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($this->recordMessage($row, $item) !== null) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Handle one `sms.received` webhook body.
     *
     * A payload for a number we do not have is logged rather than stored: it
     * means the platform is paying for a number no workspace owns, which is a
     * problem for an operator, not something to invent a row for.
     */
    public function ingestWebhook(array $payload): ?VirtualNumberMessage
    {
        $providerId = isset($payload['number_id']) && is_numeric($payload['number_id'])
            ? (int) $payload['number_id']
            : null;

        if ($providerId === null) {
            return null;
        }

        $row = VirtualNumber::where('provider_number_id', $providerId)->first();

        if ($row === null) {
            Log::warning('SMS webhook for an unknown API Way number', [
                'provider_number_id' => $providerId,
                'partner_customer_id' => $payload['partner_customer_id'] ?? null,
            ]);

            return null;
        }

        return $this->recordMessage($row, $payload);
    }

    // --- Sync --------------------------------------------------------------

    /**
     * Reconcile with API Way: statuses, renewal dates, and the two kinds of
     * mismatch that cost money.
     *
     * Runs hourly. The account is shared by every tenant, so this reads the
     * whole inventory in one call and matches it against local rows.
     *
     * @return array{synced: int, adopted: int, refunded: int, orphans: int, cancelled: int}
     */
    public function syncStatuses(): array
    {
        $remote = $this->remoteNumbers();

        $synced = 0;
        $cancelled = 0;

        foreach (VirtualNumber::query()->whereNotNull('provider_number_id')->get() as $row) {
            $data = $remote->get((int) $row->provider_number_id);

            if ($data === null) {
                // Gone upstream. Only meaningful for a row we still think is
                // live — a cancelled one disappearing is the expected end.
                if ($row->isLive()) {
                    $this->markGoneUpstream($row);
                    $cancelled++;
                }

                continue;
            }

            $this->applyRemote($row, $data);
            $synced++;
        }

        $adoptedIds = $this->adoptUnclaimed($remote);
        $refunded = $this->refundStalledPurchases();

        $orphans = $remote->keys()->diff($this->claimedProviderIds())->diff($adoptedIds);

        if ($orphans->isNotEmpty()) {
            // Numbers API Way bills us for that no workspace owns. Never
            // deleted automatically — one of them may be a purchase whose row
            // was lost, and deleting it takes a number a customer is using.
            Log::error('API Way numbers with no local owner', [
                'provider_number_ids' => $orphans->values()->all(),
            ]);
        }

        return [
            'synced' => $synced,
            'adopted' => count($adoptedIds),
            'refunded' => $refunded,
            'orphans' => $orphans->count(),
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Match numbers that exist upstream to purchases we never got an answer
     * for. This is what makes charging before provisioning safe against a
     * timeout: the number is found and handed to the tenant who paid for it.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $remote
     * @return list<int> provider ids adopted
     */
    protected function adoptUnclaimed(\Illuminate\Support\Collection $remote): array
    {
        $pending = VirtualNumber::query()
            ->where('status', VirtualNumberStatus::Pending->value)
            ->whereNull('provider_number_id')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return [];
        }

        // Cast, because the strict in_array below is what stops an already-owned
        // number being adopted a second time — and a driver that hands back
        // "512" instead of 512 would defeat it silently.
        $claimed = $this->claimedProviderIds();
        $adopted = [];

        foreach ($pending as $row) {
            // Already-adopted ids join the claimed list as we go, so two pending
            // rows for the same app and DDD cannot both take the same number.
            $match = $this->matchRemoteFor($row, $remote, array_merge($claimed, $adopted));

            if ($match === null) {
                continue;
            }

            $this->adoptRemote($row, $match);
            $adopted[] = (int) $match['id'];

            Log::info('Adopted an API Way number into an unconfirmed purchase', [
                'virtual_number_id' => $row->id,
                'provider_number_id' => $match['id'],
            ]);
        }

        return $adopted;
    }

    /**
     * Give up on purchases that never confirmed and hand the money back.
     *
     * Only reached after adoption has had its chance, and only past the stall
     * window: refunding a purchase whose number does exist upstream would leave
     * the platform paying for a number nobody owns.
     */
    protected function refundStalledPurchases(): int
    {
        $rows = VirtualNumber::query()
            ->where('status', VirtualNumberStatus::Pending->value)
            ->whereNull('provider_number_id')
            ->where('created_at', '<', now()->subMinutes(self::STALLED_PURCHASE_MINUTES))
            ->get();

        foreach ($rows as $row) {
            $this->refundUndelivered($row, 'unconfirmed', 'A API Way não confirmou a contratação.');
        }

        return $rows->count();
    }

    protected function markGoneUpstream(VirtualNumber $row): void
    {
        $meta = $row->meta ?? [];
        $meta['cancel_reason'] ??= 'upstream';

        $row->update([
            'status' => VirtualNumberStatus::Cancelled,
            'cancelled_at' => $row->cancelled_at ?? now(),
            'meta' => $meta,
        ]);

        Log::warning('Virtual number disappeared upstream', [
            'virtual_number_id' => $row->id,
            'provider_number_id' => $row->provider_number_id,
        ]);

        broadcast(new VirtualNumberUpdated($row->fresh()));
    }

    /** Copy upstream state onto the row. Never touches what the tenant paid. */
    protected function applyRemote(VirtualNumber $row, array $data): void
    {
        $status = match ($data['status'] ?? null) {
            'active' => VirtualNumberStatus::Active,
            'pending' => VirtualNumberStatus::Pending,
            'canceled', 'cancelled' => VirtualNumberStatus::Cancelled,
            default => null,
        };

        $updates = [
            // Guarded rather than cast: `(int) null` is 0, and a row carrying a
            // provider id of zero collides with the next one on the unique index.
            'provider_number_id' => isset($data['id']) && is_numeric($data['id'])
                ? (int) $data['id']
                : $row->provider_number_id,
            'msisdn' => $data['msisdn'] ?? $row->msisdn,
            'region' => $data['region'] ?? $row->region,
            'last_synced_at' => now(),
        ];

        // The upstream price is our cost, and it is worth keeping current even
        // between renewals: it is what the Back Office margin column reads.
        if (isset($data['price_cents']) && is_numeric($data['price_cents'])) {
            $updates['cost_cents'] = (int) $data['price_cents'];
        }

        if (! empty($data['renews_at'])) {
            $updates['renews_at'] = Carbon::parse($data['renews_at']);
        }

        // A local terminal state wins: we cancel upstream first and write the
        // row second, so a sync that raced the cancel must not resurrect it.
        if ($status !== null && ! $row->status->isTerminal()) {
            $updates['status'] = $status;

            if ($status === VirtualNumberStatus::Cancelled) {
                $updates['cancelled_at'] = $row->cancelled_at ?? now();
            }
        }

        $row->update($updates);
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * What API Way stores alongside the number to trace it back to the end
     * customer. Mandatory for reseller accounts, and deliberately the same
     * identifier the ProxyBR side uses, so one workspace reads the same in both
     * partners' records.
     */
    public function partnerCustomerId(Tenant $tenant): string
    {
        return 'tenant-'.$tenant->id;
    }

    protected function assertInCatalog(array $catalog, string $ddd, string $app): void
    {
        $apps = collect($catalog['apps'] ?? [])->pluck('id')->all();

        if (! in_array($app, $apps, true)) {
            throw ValidationException::withMessages(['app' => 'Aplicativo indisponível no catálogo.']);
        }

        if (! array_key_exists($ddd, $catalog['regions'] ?? [])) {
            throw ValidationException::withMessages(['ddd' => 'DDD indisponível no catálogo.']);
        }
    }

    protected function appLabel(array $catalog, string $app): string
    {
        foreach (($catalog['apps'] ?? []) as $entry) {
            if (($entry['id'] ?? null) === $app) {
                return (string) ($entry['label'] ?? $app);
            }
        }

        return $app;
    }

    protected function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    protected function trimOrNull(mixed $value, ?int $max = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }
}
