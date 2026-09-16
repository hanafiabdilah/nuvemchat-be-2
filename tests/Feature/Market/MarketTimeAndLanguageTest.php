<?php

use App\Models\Connection;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BusinessHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

/**
 * What hour it is for a workspace, and what language a person reads in.
 *
 * Both of these were platform-wide constants, and both were wrong in a way that
 * could not be seen from inside the only country that existed. The app timezone
 * is 'UTC', so every date the platform printed for a human was a UTC date and
 * every default service-hours schedule started three hours off São Paulo; the
 * language lived only in a browser's localStorage, so a choice never followed
 * anyone to a second device and the workspace's own country never got a say.
 *
 * These tests exist because neither failure announces itself: a renewal warning
 * naming the wrong day and a dashboard in the wrong language both look like
 * working software.
 */
uses(RefreshDatabase::class);

// Named for this file on purpose. Pest loads every test file into one process,
// so a second helper of the same name anywhere is a fatal redeclare — and
// borrowing a sibling file's helper breaks the moment this file runs alone.
function clockIndonesia(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

/**
 * A workspace in a named market. `market_code` is deliberately outside
 * $fillable (the domain decides, never a request body), so it is assigned here
 * rather than passed to create().
 */
function clockTenant(string $marketCode): Tenant
{
    $user = User::factory()->create();

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->refresh();
}

it('starts a workspace on the clock its country keeps', function () {
    clockIndonesia();

    expect(clockTenant('ID')->timezone)->toBe('Asia/Jakarta')
        ->and(clockTenant('BR')->timezone)->toBe('America/Sao_Paulo');
});

it('keeps a workspace where it was when its market default is corrected later', function () {
    $market = clockIndonesia();
    $tenant = clockTenant('ID');

    // Somebody in the Back Office decides Indonesia should default to Makassar.
    $market->forceFill(['default_timezone' => 'Asia/Makassar'])->save();

    // The workspace that was already open does not move: its business hours and
    // renewal dates were set against Jakarta, and an edit to a country default
    // is not a statement about a business that already told us where it is.
    expect($tenant->fresh()->displayTimezone())->toBe('Asia/Jakarta');
});

it('falls back to the market only for rows written before the column existed', function () {
    clockIndonesia();
    $tenant = clockTenant('ID');

    // Simulated without the hook, the way the pre-migration rows really are.
    DB::table('tenants')->where('id', $tenant->id)->update(['timezone' => null]);

    expect(Tenant::find($tenant->id)->displayTimezone())->toBe('Asia/Jakarta');
});

it('writes a due date as the workspace own day, not the database one', function () {
    clockIndonesia();

    // One instant, and it is a different date in each country: 02:00 UTC is
    // still the previous evening in São Paulo and already mid-morning in
    // Jakarta. Printing the stored column would tell one of them the wrong day
    // no matter which country the platform happened to be written in.
    $moment = Carbon::parse('2026-09-20T02:00:00Z');

    expect(clockTenant('BR')->formatDate($moment))->toBe('19/09/2026')
        ->and(clockTenant('ID')->formatDate($moment))->toBe('20/09/2026');
});

it('does not mutate the date it was handed', function () {
    $moment = Carbon::parse('2026-09-20T02:00:00Z');

    clockTenant('BR')->formatDate($moment);

    // Carbon's timezone() changes the instance in place, so without a copy this
    // method quietly rewrites the caller's own model attribute — and the next
    // read of it would be in a zone nobody asked for.
    //
    // Asserted as the wall clock rather than the zone's name: a Carbon parsed
    // from a "…Z" string names its zone 'Z', not 'UTC', and that spelling is
    // not what this is about. Shifted to São Paulo it would read 23:00.
    expect($moment->format('Y-m-d H:i'))->toBe('2026-09-20 02:00');
});

it('offers a new schedule in the workspace hours rather than in UTC', function () {
    clockIndonesia();

    // The bug this replaced: config('app.timezone', 'America/Sao_Paulo') looks
    // like it defaults to Brazil, but the key exists and holds 'UTC', so the
    // fallback never fired and every workspace was offered London's hours.
    expect(BusinessHours::defaultConfig(clockTenant('ID'))['timezone'])->toBe('Asia/Jakarta')
        ->and(BusinessHours::defaultConfig(clockTenant('BR'))['timezone'])->toBe('America/Sao_Paulo');
});

it('judges a connection with no stored zone by its workspace clock', function () {
    clockIndonesia();

    $alwaysOpenDays = [];
    foreach (BusinessHours::DAYS as $day) {
        $alwaysOpenDays[$day] = [['open' => '08:00', 'close' => '22:00']];
    }

    $withoutZone = function (Tenant $tenant) use ($alwaysOpenDays) {
        $connection = new Connection;
        $connection->service_hours = ['enabled' => true, 'days' => $alwaysOpenDays];
        $connection->setRelation('tenant', $tenant);

        return $connection;
    };

    // 02:00 UTC is 09:00 in Jakarta — open — while UTC itself would call it shut.
    expect(BusinessHours::isOpen($withoutZone(clockTenant('ID')), Carbon::parse('2026-09-14T02:00:00Z')))->toBeTrue();

    // 23:00 UTC is 20:00 in São Paulo — open — while UTC would call it shut too.
    expect(BusinessHours::isOpen($withoutZone(clockTenant('BR')), Carbon::parse('2026-09-14T23:00:00Z')))->toBeTrue();
});

it('writes a date the way the reader country writes it', function () {
    Market::create([
        'code' => 'US',
        'name' => 'United States',
        'currency' => 'USD',
        'default_locale' => 'en',
        'default_timezone' => 'America/New_York',
        'phone_country' => '1',
        'status' => 'active',
    ]);

    // 02:00 UTC on the 20th is the evening of the 19th in both New York and São
    // Paulo, so the day is the same and only the notation differs — which is
    // the whole point: 09/19 and 19/09 are the same date written for two
    // readers, and handing either one the other's is a due date that means a
    // different day.
    $moment = Carbon::parse('2026-09-20T02:00:00Z');

    expect(clockTenant('BR')->formatDate($moment))->toBe('19/09/2026')
        ->and(clockTenant('US')->formatDate($moment))->toBe('09/19/2026');
});

it('writes the time the same way', function () {
    clockIndonesia();

    // 02:00 UTC = 09:00 in Jakarta, on the workspace's own clock and in its
    // own notation.
    expect(clockTenant('ID')->formatDateTime(Carbon::parse('2026-09-20T02:00:00Z')))
        ->toBe('20/09/2026 09:00');
});

it('tells a workspace which clock it is on and which one its country picked', function () {
    clockIndonesia();
    $tenant = clockTenant('ID');

    Sanctum::actingAs($tenant->user);

    $this->getJson('/api/workspace')
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Jakarta')
        // Both, so the form can say whether this workspace is following its
        // country or has been moved off it.
        ->assertJsonPath('data.market_timezone', 'Asia/Jakarta');
});

it('lets a workspace correct the clock its country guessed', function () {
    $tenant = clockTenant('BR');
    $user = $tenant->user;
    $user->givePermissionTo(Permission::findOrCreate('billing.manage', 'web'));

    Sanctum::actingAs($user);

    // Brazil spans four zones, so the country default is a starting guess and
    // a business in Manaus has to be able to say so.
    $this->putJson('/api/workspace', ['timezone' => 'America/Manaus'])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'America/Manaus');

    expect($tenant->fresh()->displayTimezone())->toBe('America/Manaus');
});

it('refuses a zone no clock in this build knows', function () {
    $tenant = clockTenant('BR');
    $user = $tenant->user;
    $user->givePermissionTo(Permission::findOrCreate('billing.manage', 'web'));

    Sanctum::actingAs($user);

    // Accepting it here would surface months later as a renewal notice
    // throwing inside a scheduler, with nobody at a screen.
    $this->putJson('/api/workspace', ['timezone' => 'Mars/Olympus_Mons'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');
});

it('does not let just anyone move the workspace clock', function () {
    Sanctum::actingAs(clockTenant('BR')->user);

    // Readable by everyone in the workspace — the zone explains every date they
    // are shown — but changing it is a company-level act.
    $this->getJson('/api/workspace')->assertOk();
    $this->putJson('/api/workspace', ['timezone' => 'America/Manaus'])->assertForbidden();
});

it('remembers the language a person chose', function () {
    $tenant = clockTenant('BR');
    $user = $tenant->user;

    Sanctum::actingAs($user);

    $this->putJson('/api/user/preferences', ['locale' => 'id'])
        ->assertOk()
        ->assertJsonPath('locale', 'id');

    expect($user->fresh()->locale)->toBe('id');

    // And it reaches the dashboard on the next load, which is the whole point:
    // before this the choice lived in one browser's localStorage and a second
    // device started over.
    $this->getJson('/api/user')->assertOk()->assertJsonPath('data.locale', 'id');
});

it('refuses a language no market could have been launched in', function () {
    Sanctum::actingAs(clockTenant('BR')->user);

    $this->putJson('/api/user/preferences', ['locale' => 'xx'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('locale');
});

it('lets a person hand the choice back to their country', function () {
    $tenant = clockTenant('BR');
    $user = $tenant->user;
    $user->forceFill(['locale' => 'id'])->save();

    Sanctum::actingAs($user);

    // Null is a real value here, not a missing field: it is the only way back
    // to "whatever my workspace speaks" once something was picked.
    $this->putJson('/api/user/preferences', ['locale' => null])
        ->assertOk()
        ->assertJsonPath('locale', null);

    expect($user->fresh()->locale)->toBeNull();
});

it('leaves the theme alone when only the language changes', function () {
    $user = clockTenant('BR')->user;
    $user->forceFill(['ui_preferences' => ['theme' => 'studio']])->save();

    Sanctum::actingAs($user);

    // The settings page saves one control at a time, so a client that predates
    // the language field must not have its theme wiped by one that doesn't.
    $this->putJson('/api/user/preferences', ['locale' => 'id'])->assertOk();

    expect($user->fresh()->ui_preferences)->toBe(['theme' => 'studio']);
});
