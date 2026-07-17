<?php

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BusinessHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeTenantWithUser(): Tenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    return $tenant;
}

function makeConnection(Tenant $tenant, string $name, ?array $serviceHours = null): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => $name,
        'color' => '#22c55e',
        'status' => 'active',
        'service_hours' => $serviceHours,
    ]);
}

/** Open on Mondays only, 09:00–18:00 São Paulo. */
function mondayOnlyHours(string $timezone = 'America/Sao_Paulo', string $awayMessage = ''): array
{
    return [
        'enabled' => true,
        'timezone' => $timezone,
        'days' => [
            'mon' => [['open' => '09:00', 'close' => '18:00']],
            'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
        ],
        'away_message' => $awayMessage,
    ];
}

test('two connections in the same tenant keep independent schedules', function () {
    $tenant = makeTenantWithUser();

    $openMondays = makeConnection($tenant, 'Company A', mondayOnlyHours());
    $closedMondays = makeConnection($tenant, 'Company B', [
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'days' => [
            'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [],
            'sun' => [['open' => '09:00', 'close' => '18:00']],
        ],
        'away_message' => '',
    ]);

    $mondayAt10 = Carbon::parse('2026-07-20 10:00:00', 'America/Sao_Paulo');

    expect(BusinessHours::isOpen($openMondays, $mondayAt10))->toBeTrue();
    expect(BusinessHours::isOpen($closedMondays, $mondayAt10))->toBeFalse();
});

test('an unconfigured connection is treated as always open', function () {
    $tenant = makeTenantWithUser();
    $connection = makeConnection($tenant, 'No schedule');

    expect(BusinessHours::isOpen($connection))->toBeTrue();
    expect(BusinessHours::awayMessage($connection))->toBeNull();
});

test('a disabled schedule is treated as always open', function () {
    $tenant = makeTenantWithUser();
    $connection = makeConnection($tenant, 'Disabled', array_merge(mondayOnlyHours(), ['enabled' => false]));

    // Sunday — outside the configured Monday window, but the schedule is off.
    $sunday = Carbon::parse('2026-07-19 10:00:00', 'America/Sao_Paulo');
    expect(BusinessHours::isOpen($connection, $sunday))->toBeTrue();
});

test('the closing time is exclusive', function () {
    $tenant = makeTenantWithUser();
    $connection = makeConnection($tenant, 'Company A', mondayOnlyHours());

    expect(BusinessHours::isOpen($connection, Carbon::parse('2026-07-20 17:59:00', 'America/Sao_Paulo')))->toBeTrue();
    expect(BusinessHours::isOpen($connection, Carbon::parse('2026-07-20 18:00:00', 'America/Sao_Paulo')))->toBeFalse();
});

test('each connection is evaluated in its own timezone', function () {
    $tenant = makeTenantWithUser();
    $saoPaulo = makeConnection($tenant, 'SP', mondayOnlyHours('America/Sao_Paulo'));
    $utc = makeConnection($tenant, 'UTC', mondayOnlyHours('UTC'));

    // 12:00 UTC on Monday == 09:00 in São Paulo: both are inside 09:00–18:00.
    $noonUtc = Carbon::parse('2026-07-20 12:00:00', 'UTC');
    expect(BusinessHours::isOpen($saoPaulo, $noonUtc))->toBeTrue();
    expect(BusinessHours::isOpen($utc, $noonUtc))->toBeTrue();

    // 23:00 UTC == 20:00 São Paulo: both are past their close.
    $elevenPmUtc = Carbon::parse('2026-07-20 23:00:00', 'UTC');
    expect(BusinessHours::isOpen($saoPaulo, $elevenPmUtc))->toBeFalse();
    expect(BusinessHours::isOpen($utc, $elevenPmUtc))->toBeFalse();

    // 09:30 UTC == 06:30 São Paulo: only the UTC connection is open.
    $halfPastNineUtc = Carbon::parse('2026-07-20 09:30:00', 'UTC');
    expect(BusinessHours::isOpen($utc, $halfPastNineUtc))->toBeTrue();
    expect(BusinessHours::isOpen($saoPaulo, $halfPastNineUtc))->toBeFalse();
});

test('the away message is read from the connection', function () {
    $tenant = makeTenantWithUser();
    $connection = makeConnection($tenant, 'A', mondayOnlyHours(awayMessage: 'We are closed.'));

    expect(BusinessHours::awayMessage($connection))->toBe('We are closed.');
});
