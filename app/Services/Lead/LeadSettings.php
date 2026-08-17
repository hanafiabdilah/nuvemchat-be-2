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
    ) {}

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
        ];
    }

    public static function clampDays(int $days): int
    {
        return max(self::MIN_AUTO_CLOSE_DAYS, min(self::MAX_AUTO_CLOSE_DAYS, $days));
    }
}
