<?php

namespace Tests\Support;

use App\Models\FlowNode;
use App\Models\ReservableService;
use App\Models\Tenant;
use App\Services\Flow\AiToolNodes;
use Carbon\CarbonImmutable;

/**
 * Setup for the reservation suites: services with hours every day, and a
 * WhatsApp conversation parked on an ai_tools node that takes reservations
 * (built on CatalogFixtures, so the hub, WhatsApp and OpenPix fakes are the
 * same ones).
 *
 * A class, not Pest helpers — Pest loads every test file into one process.
 */
final class ReservationFixtures
{
    /** @param  array<string, mixed>  $attributes */
    public static function service(Tenant $tenant, array $attributes = []): ReservableService
    {
        $hours = [['open' => '09:00', 'close' => '22:00']];

        return ReservableService::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Mesa',
            'active' => true,
            'duration_minutes' => 60,
            'capacity' => 2,
            'capacity_unit' => 'bookings',
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $hours),
            'date_overrides' => [],
            'min_notice_minutes' => 0,
            'max_days_ahead' => 60,
            'auto_confirm' => true,
            'cancel_until_hours' => 24,
            'payment_mode' => 'none',
        ], $attributes))->load('tenant');
    }

    /**
     * An ai_tools node that takes reservations (and does not sell), with a
     * "Reservation confirmed" step drawn after it.
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed> CatalogFixtures::scenario() plus `confirmed`
     */
    public static function scenario(array $capabilities = []): array
    {
        $scene = CatalogFixtures::scenario(array_replace_recursive([
            'catalog' => false,
            'cart' => false,
            'reservations' => ['enabled' => true],
        ], $capabilities));

        $confirmed = IntegrationFixtures::say($scene['node']->flow, 'Te esperamos!', 560, 500);
        IntegrationFixtures::edge($scene['node'], $confirmed, AiToolNodes::BRANCH_RESERVATION_CONFIRMED);

        return $scene + ['confirmed' => $confirmed];
    }

    /** A local date a few days ahead, in the workspace's clock. */
    public static function day(Tenant $tenant, int $daysAhead = 2): string
    {
        return CarbonImmutable::now($tenant->displayTimezone())->addDays($daysAhead)->format('Y-m-d');
    }

    public static function localStart(Tenant $tenant, string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", $tenant->displayTimezone());
    }

    public static function currentNode(array $scene): ?FlowNode
    {
        return $scene['conversation']->fresh()->flowState?->currentNode;
    }
}
