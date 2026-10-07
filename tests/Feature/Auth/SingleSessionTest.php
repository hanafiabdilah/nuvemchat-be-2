<?php

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
 * One sign-in per account: signing in somewhere else ends the session that was
 * there before, and that device is told why.
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

/** Signs in over HTTP and hands back the token, the way a browser would get it. */
function signInAs(User $user, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0 Safari/537.36'): string
{
    forgetWhoSignedIn();

    $token = test()->withHeaders(['User-Agent' => $userAgent])
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

it('tells the ended device why, and which device took over', function () {
    $user = singleSessionUser();

    $first = signInAs($user);
    signInAs($user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile Safari/604.1');

    callWith($first)
        ->assertStatus(401)
        ->assertJsonPath('code', 'session_superseded')
        ->assertJsonPath('device', 'Safari · iOS');
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
