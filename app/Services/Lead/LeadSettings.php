<?php

namespace App\Services\Lead;

use App\Models\Tenant;

/**
 * One workspace's funnel preferences, with the defaults in one place.
 *
 * Read from tenants.lead_settings, which is null until somebody opens the
 * dialog. Every field therefore has to have a sensible default here rather
 * than in a migration — that is what lets the feature work on day one for
 * every existing tenant without backfilling anything.
 */
final class LeadSettings
{
    /**
     * Long enough that a customer who is simply thinking it over is not written
     * off, short enough that the board still reflects reality. Tenants who sell
     * furniture and tenants who sell haircuts will both want to change it,
     * which is exactly why it is a setting.
     */
    public const DEFAULT_AUTO_CLOSE_DAYS = 30;

    public const MIN_AUTO_CLOSE_DAYS = 3;

    public const MAX_AUTO_CLOSE_DAYS = 365;

    private function __construct(
        public readonly bool $autoCreate,
        public readonly bool $autoCloseEnabled,
        public readonly int $autoCloseDays,
        /**
         * Whether a card that has been advanced past the first stage may be
         * closed automatically.
         *
         * Off by default, and the safest knob in here. A lead sitting in
         * Negociação for R$ 3.400 has a human behind it who decided it was
         * worth pursuing; retiring that silently is a very different act from
         * clearing out someone who asked a price once and never wrote again.
         */
        public readonly bool $autoCloseEngaged,
        /**
         * The stage a card moves to the first time someone from the team
         * answers the contact (see LeadAttendance). Null switches it off.
         *
         * A stage id rather than a name: tenants rename columns freely, and a
         * rule keyed on "Atendidos" would silently stop working the day
         * someone calls it "Em atendimento".
         */
        public readonly ?int $attendedStageId = null,
        /**
         * Leads enter only through the public API (POST /api/v1/leads).
         *
         * Stronger than switching `auto_create` off, which still leaves two
         * other doors open — a flow's Lead node and adding one by hand. A
         * workspace whose funnel is fed by another system wants a board where
         * every card is one that system sent, and a single stray card from a
         * conversation is enough to make the board untrustworthy.
         *
         * Cards that already exist are untouched and can still be worked,
         * moved and closed from anywhere.
         */
        public readonly bool $apiOnly = false,
    ) {}

    /**
     * Whether something other than the public API may open a new card.
     */
    public function acceptsOwnLeads(): bool
    {
        return ! $this->apiOnly;
    }

    public static function for(Tenant $tenant): self
    {
        return self::fromArray($tenant->lead_settings ?? []);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            autoCreate: (bool) ($raw['auto_create'] ?? true),
            autoCloseEnabled: (bool) ($raw['auto_close_enabled'] ?? true),
            autoCloseDays: self::clampDays((int) ($raw['auto_close_days'] ?? self::DEFAULT_AUTO_CLOSE_DAYS)),
            autoCloseEngaged: (bool) ($raw['auto_close_engaged'] ?? false),
            attendedStageId: (int) ($raw['attended_stage_id'] ?? 0) > 0 ? (int) $raw['attended_stage_id'] : null,
            apiOnly: (bool) ($raw['api_only'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'auto_create' => $this->autoCreate,
            'auto_close_enabled' => $this->autoCloseEnabled,
            'auto_close_days' => $this->autoCloseDays,
            'auto_close_engaged' => $this->autoCloseEngaged,
            'attended_stage_id' => $this->attendedStageId,
            'api_only' => $this->apiOnly,
        ];
    }

    public static function clampDays(int $days): int
    {
        return max(self::MIN_AUTO_CLOSE_DAYS, min(self::MAX_AUTO_CLOSE_DAYS, $days));
    }
}
