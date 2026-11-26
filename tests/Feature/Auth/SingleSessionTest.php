<?php

use App\Enums\Auth\SessionDevice;
use App\Events\SessionSuperseded;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SingleSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One computer and one phone per account: signing in on a computer ends the
 * computer that was signed in before (and a phone the phone), the other kind
 * stays, and the device that lost is told why.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('sign-in:127.0.0.1');
});

function singleSessionUser(): User
{
    $user = User::factory()->create([
        'email' => 'agent-'.uniqid().'@example.test',
        'password' => Hash::make('correct-horse-battery'),
    ]);

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user;
}

const COMPUTER_UA = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0 Safari/537.36';
const MAC_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Version/17.0 Safari/605.1.15';
const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile Safari/604.1';
const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36';

/** Signs in over HTTP and hands back the token, the way a browser would get it. */
function signInAs(User $user, string $userAgent = COMPUTER_UA, array $headers = []): string
{
    forgetWhoSignedIn();

    $token = test()->withHeaders(['User-Agent' => $userAgent] + $headers)
        ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->json('access_token');

    forgetWhoSignedIn();

    return $token;
}

/**
 * Auth::attempt() leaves the user on the web guard and in the test's in-memory
 * session, and Sanctum asks that guard before it looks at a bearer token. In
 * production nothing carries over (the API starts no session); in a test both
 * have to be dropped, or every call authenticates no matter what it sends.
 */
function forgetWhoSignedIn(): void
{
    session()->flush();
    Auth::forgetGuards();
    // An authenticated call leaves `sanctum` as the default guard, which has
    // no attempt() — the next sign-in in the same test would not even run.
    Auth::shouldUse('web');
}

function callWith(string $token)
{
    forgetWhoSignedIn();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/user');
}

it('ends the earlier session when the account signs in again', function () {
    $user = singleSessionUser();

    $first = signInAs($user);
    callWith($first)->assertOk();

    $second = signInAs($user);

    callWith($second)->assertOk();
    callWith($first)->assertStatus(401);

    expect($user->tokens()->count())->toBe(1);
});

it('keeps a computer and a phone signed in together', function () {
    $user = singleSessionUser();

    $computer = signInAs($user, COMPUTER_UA);
    $phone = signInAs($user, PHONE_UA);

    callWith($computer)->assertOk();
    callWith($phone)->assertOk();

    expect($user->tokens()->pluck('device_kind')->sort()->values()->all())->toBe(['computer', 'phone']);
});

it('ends only the other computer when a second computer signs in', function () {
    $user = singleSessionUser();

    $firstComputer = signInAs($user, COMPUTER_UA);
    $phone = signInAs($user, PHONE_UA);
    $secondComputer = signInAs($user, MAC_UA);

    callWith($firstComputer)->assertStatus(401);
    callWith($phone)->assertOk();
    callWith($secondComputer)->assertOk();
});

it('ends only the other phone when a second phone signs in', function () {
    $user = singleSessionUser();

    $computer = signInAs($user, COMPUTER_UA);
    $firstPhone = signInAs($user, PHONE_UA);
    $secondPhone = signInAs($user, ANDROID_UA);

    callWith($firstPhone)->assertStatus(401);
    callWith($computer)->assertOk();
    callWith($secondPhone)->assertOk();
});

it('ends a session from before kinds were kept, whatever signs in next', function () {
    $user = singleSessionUser();

    $legacy = $user->createToken(SingleSession::TOKEN_NAME)->plainTextToken;
    $phone = signInAs($user, PHONE_UA);

    callWith($legacy)->assertStatus(401);
    callWith($phone)->assertOk();
});

it('believes the browser\'s own mobile hint over a desktop-looking user agent', function () {
    $user = singleSessionUser();

    $computer = signInAs($user, COMPUTER_UA);
    // Chrome on Android with "Request desktop site" still says ?1… or a
    // phone browser whose user agent was reduced to a desktop shape.
    signInAs($user, 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0 Safari/537.36', ['Sec-CH-UA-Mobile' => '?1']);

    callWith($computer)->assertOk();
});

it('tells the ended device why, and which device took over', function () {
    $user = singleSessionUser();

    $first = signInAs($user, PHONE_UA);
    signInAs($user, ANDROID_UA);

    callWith($first)
        ->assertStatus(401)
        ->assertJsonPath('code', 'session_superseded')
        ->assertJsonPath('device', 'Chrome · Android')
        ->assertJsonPath('kind', 'phone');
});

it('marks the session a new account starts with', function () {
    \Spatie\Permission\Models\Role::findOrCreate('owner', 'web');
    forgetWhoSignedIn();

    $token = test()->withHeaders(['User-Agent' => PHONE_UA])
        ->postJson('/api/auth/register', [
            'name' => 'Ana',
            'email' => 'ana-'.uniqid().'@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'whatsapp_number' => '5511999990000',
        ])
        ->assertCreated()
        ->json('access_token');

    $row = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

    expect($row->device_kind)->toBe('phone')
        ->and($row->device_label)->toBe('Safari · iOS');
});

it('announces it in real time on the person\'s own channel, naming the session that was ended', function () {
    Event::fake([SessionSuperseded::class]);

    $user = singleSessionUser();

    $first = signInAs($user);

    // A first sign-in ends nothing, so there is nothing to announce.
    Event::assertNotDispatched(SessionSuperseded::class);

    $second = signInAs($user);

    Event::assertDispatched(SessionSuperseded::class, function (SessionSuperseded $event) use ($user, $first, $second) {
        $ended = $event->broadcastWith()['ended'];

        return $event->userId === $user->id
            && $event->broadcastWith()['kind'] === 'computer'
            && $ended === [explode('|', $first)[0]]
            && ! in_array(explode('|', $second)[0], $ended, true)
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
            && $event->broadcastAs() === 'session-superseded';
    });
});

it('leaves other people\'s sessions alone', function () {
    $user = singleSessionUser();
    $colleague = singleSessionUser();

    $theirs = signInAs($colleague);
    signInAs($user);

    callWith($theirs)->assertOk();
});

it('does not let a customer\'s sign-in end a Back Office impersonation, nor the reverse', function () {
    $user = singleSessionUser();

    $operator = $user->createToken('impersonation', ['*'], now()->addHour())->plainTextToken;
    $mine = signInAs($user);

    callWith($operator)->assertOk();
    callWith($mine)->assertOk();
});

it('answers an unknown or expired token with a plain 401', function () {
    callWith('999|not-a-real-token')
        ->assertStatus(401)
        ->assertJsonMissingPath('code');
});

it('keeps every session when the rule is switched off', function () {
    config(['single_session.enabled' => false]);

    $user = singleSessionUser();

    $first = signInAs($user);
    $second = signInAs($user);

    callWith($first)->assertOk();
    callWith($second)->assertOk();
});

it('does not refuse a sign-in because the announcement failed', function () {
    Event::listen(SessionSuperseded::class, fn () => throw new RuntimeException('reverb is down'));

    $user = singleSessionUser();

    $first = signInAs($user);
    $second = signInAs($user);

    callWith($second)->assertOk();
    callWith($first)->assertStatus(401);
});

it('names a device from its user agent', function (string $userAgent, ?string $label) {
    expect(SingleSession::deviceLabel($userAgent))->toBe($label);
})->with([
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36', 'Chrome · Windows'],
    ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/126.0 Safari/537.36 Edg/126.0', 'Edge · Windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Version/17.0 Safari/605.1.15', 'Safari · macOS'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox · Linux'],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36 PinglyApp/1.2.0', 'Pingly app · Android'],
    ['', null],
]);

it('reads the kind of device from its user agent', function (string $userAgent, SessionDevice $kind) {
    expect(SessionDevice::fromUserAgent($userAgent))->toBe($kind);
})->with([
    [COMPUTER_UA, SessionDevice::Computer],
    [MAC_UA, SessionDevice::Computer],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', SessionDevice::Computer],
    [PHONE_UA, SessionDevice::Phone],
    [ANDROID_UA, SessionDevice::Phone],
    // An Android tablet leaves "Mobile" out; it still counts as the phone.
    ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/126.0 Safari/537.36', SessionDevice::Phone],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1', SessionDevice::Phone],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36 PinglyApp/1.2.0', SessionDevice::Phone],
    ['', SessionDevice::Computer],
]);

it('lists where the account is signed in, this device first', function () {
    $user = singleSessionUser();

    $phone = signInAs($user, PHONE_UA);
    $computer = signInAs($user, COMPUTER_UA);
    // A Back Office operator is not one of the person's devices.
    $user->createToken('impersonation', ['*'], now()->addHour());

    forgetWhoSignedIn();

    $sessions = test()->withHeaders(['Authorization' => 'Bearer '.$computer])
        ->getJson('/api/user/sessions')
        ->assertOk()
        ->assertJsonPath('one_per_kind', true)
        ->json('data');

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0])->toMatchArray([
            'id' => explode('|', $computer)[0],
            'kind' => 'computer',
            'device' => 'Chrome · Windows',
            'current' => true,
        ])
        ->and($sessions[1])->toMatchArray([
            'id' => explode('|', $phone)[0],
            'kind' => 'phone',
            'device' => 'Safari · iOS',
            'current' => false,
        ]);
});

it('leaves sessions Sanctum already treats as expired off the list', function () {
    $user = singleSessionUser();

    $computer = signInAs($user, COMPUTER_UA);
    $old = $user->createToken(SingleSession::TOKEN_NAME);
    $old->accessToken->forceFill(['device_kind' => 'phone', 'created_at' => now()->subDays(30)])->save();

    forgetWhoSignedIn();

    test()->withHeaders(['Authorization' => 'Bearer '.$computer])
        ->getJson('/api/user/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('keeps the Pingly app\'s phones as phones when the column arrives', function () {
    $user = singleSessionUser();

    $app = $user->createToken(SingleSession::TOKEN_NAME);
    $browser = $user->createToken(SingleSession::TOKEN_NAME);

    $migration = require database_path('migrations/2026_11_26_000200_add_device_to_personal_access_tokens.php');
    $migration->down();

    \Illuminate\Support\Facades\DB::table('device_tokens')->insert([
        'tenant_id' => $user->tenant_id,
        'user_id' => $user->id,
        'personal_access_token_id' => $app->accessToken->id,
        'device_id' => 'install-1',
        'token' => 'fcm-1',
        'platform' => 'ios',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(\Illuminate\Support\Facades\DB::table('personal_access_tokens')->find($app->accessToken->id))
        ->device_kind->toBe('phone')
        ->device_label->toBe('Pingly app · iOS')
        ->and(\Illuminate\Support\Facades\DB::table('personal_access_tokens')->find($browser->accessToken->id))
        ->device_kind->toBeNull();
});
