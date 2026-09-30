<?php

namespace App\Services\Billing;

use App\Exceptions\UserFacingException;
use App\Models\SavedCard;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\SavesCards;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The workspace's saved cards, kept at the gateway that bills it (today: Mercado
 * Pago in Brazil, PAYMENT_METHOD=direct).
 *
 * A card only ever enters this list through a successful charge: the checkout
 * saves a new card first (Mercado Pago needs the card on the customer to charge
 * it as one) and BillingService takes it back out if that first charge fails —
 * see discardIfNeverUsed(). Cards added here directly are not a thing the
 * product offers.
 */
class SavedCardService
{
    /** Enough for a company card, a backup and a few spares; not a card vault. */
    public const MAX_CARDS = 10;

    public function __construct(protected BillingGateways $gateways) {}

    /** The gateway that bills this workspace, when it keeps cards. */
    public function gatewayFor(Tenant $tenant): ?SavesCards
    {
        $gateway = $this->gateways->forTenant($tenant);

        return $gateway instanceof SavesCards && $gateway->isConfigured() ? $gateway : null;
    }

    /** @return Collection<int, SavedCard> */
    public function list(Tenant $tenant): Collection
    {
        $gateway = $this->gateways->forTenant($tenant);

        if (! $gateway instanceof SavesCards) {
            return collect();
        }

        return SavedCard::query()
            ->where('tenant_id', $tenant->id)
            ->where('gateway', $gateway->name())
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();
    }

    /** A card of this workspace, on the gateway that bills it now — or a refusal. */
    public function find(Tenant $tenant, int $id): SavedCard
    {
        $card = $this->list($tenant)->firstWhere('id', $id);

        if (! $card) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'saved_card_id' => __('This saved card is no longer available.'),
            ]);
        }

        if ($card->isExpired()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'saved_card_id' => __('This card has expired. Use another card.'),
            ]);
        }

        return $card;
    }

    /**
     * Keep the card behind this token for the workspace.
     *
     * The gateway customer is looked up by the payer's email (one per email at
     * Mercado Pago), and the email is kept with the card: a later charge on it
     * has to name that same payer.
     */
    public function add(Tenant $tenant, string $cardToken, ?User $by = null): SavedCard
    {
        $gateway = $this->gatewayFor($tenant);

        if ($gateway === null) {
            throw new UserFacingException('Cartões salvos não estão disponíveis para este workspace.', 422, 'saved_cards_unavailable');
        }

        if ($this->list($tenant)->count() >= self::MAX_CARDS) {
            throw new UserFacingException(
                'Este workspace já tem o número máximo de cartões salvos. Remova um para adicionar outro.',
                422,
                'saved_cards_limit',
            );
        }

        $email = $by?->email ?: $tenant->user?->email;
        $customerId = $gateway->findOrCreateCustomer([
            'email' => $email,
            'name' => $tenant->billing_name ?: $by?->name,
            'document_type' => $tenant->billing_document_type,
            'document_number' => $tenant->billing_document_number,
        ]);

        $card = $gateway->saveCard($customerId, $cardToken);

        return SavedCard::updateOrCreate(
            ['gateway' => $this->gateways->forTenant($tenant)->name(), 'card_id' => $card['id']],
            [
                'tenant_id' => $tenant->id,
                'customer_id' => $customerId,
                'customer_email' => $email,
                'brand' => $card['brand'],
                'payment_type' => $card['payment_type'],
                'issuer_id' => $card['issuer_id'],
                'first_six' => $card['first_six'],
                'last_four' => $card['last_four'],
                'exp_month' => $card['exp_month'],
                'exp_year' => $card['exp_year'],
                'holder_name' => $card['holder_name'],
                'created_by' => $by?->id,
            ],
        );
    }

    /**
     * Forget the card, here and at the gateway.
     *
     * A subscription already running on this card keeps running: the gateway's
     * recurring authorisation holds its own copy of the card, and removing it
     * from the list is not a way to stop being charged — cancelling is.
     */
    public function remove(SavedCard $card): void
    {
        $gateway = $this->gateways->named($card->gateway);

        if ($gateway instanceof SavesCards) {
            try {
                $gateway->deleteCard($card->customer_id, $card->card_id);
            } catch (\Throwable $e) {
                // Removing it from the list is what the customer asked for; a
                // card left at the gateway is unreachable without our row.
                Log::warning('Could not delete a saved card at the gateway', [
                    'saved_card_id' => $card->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $card->delete();
    }

    public function markUsed(SavedCard $card): void
    {
        $card->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * A card added for a charge that then failed never belonged in the list:
     * it would sit there looking usable after the one time it was tried.
     */
    public function discardIfNeverUsed(SavedCard $card): void
    {
        if ($card->fresh()?->last_used_at === null) {
            $this->remove($card);
        }
    }
}
