<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Jobs\RunBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

/** A campaign sitting in $status with $count recipients still pending. */
function campaignAwaitingTick($user, $connection, Status $status, int $count = 2): Broadcast
{
    $manual = collect(range(1, $count))
        ->map(fn (int $i) => ['address' => '55119999900' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)])
        ->all();

    $id = test()->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
            'manual_recipients' => $manual,
        ]))
        ->assertCreated()
        ->json('data.id');

    $broadcast = Broadcast::findOrFail($id);
    $broadcast->update(['status' => $status]);

    return $broadcast;
}

test('a scheduled campaign whose time has come starts', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Scheduled);
    $broadcast->update(['scheduled_at' => now()->subMinute()]);

    $this->artisan('broadcasts:tick')->assertSuccessful();

    expect($broadcast->fresh()->status)->toBe(Status::Completed)
        ->and($broadcast->fresh()->sent_count)->toBe(2);
});

test('a campaign scheduled for later is left alone', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Scheduled);
    $broadcast->update(['scheduled_at' => now()->addHour()]);

    $this->artisan('broadcasts:tick')->assertSuccessful();

    expect($broadcast->fresh()->status)->toBe(Status::Scheduled);
    Bus::assertNotDispatched(RunBroadcastJob::class);
});

test('recipients a dead worker left claimed are handed back', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Running, 3);

    $recipients = BroadcastRecipient::where('broadcast_id', $broadcast->id)->orderBy('id')->get();

    // One claimed long ago (worker died), one claimed a moment ago (still going).
    $recipients[0]->forceFill(['status' => RecipientStatus::Sending, 'updated_at' => now()->subMinutes(10)])->save();
    $recipients[1]->forceFill(['status' => RecipientStatus::Sending, 'updated_at' => now()])->save();

    $this->artisan('broadcasts:tick')->assertSuccessful();

    expect($recipients[0]->fresh()->status)->toBe(RecipientStatus::Pending)
        ->and($recipients[1]->fresh()->status)->toBe(RecipientStatus::Sending);
});

test('a running campaign that stopped ticking is pushed again', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Running);
    $broadcast->update(['last_tick_at' => now()->subMinutes(30)]);

    $this->artisan('broadcasts:tick')->assertSuccessful();

    Bus::assertDispatched(RunBroadcastJob::class);
    // Stamped before dispatching, so the next tick does not pile on a second pump.
    expect($broadcast->fresh()->last_tick_at->diffInMinutes(now()))->toBeLessThan(1);
});

test('a campaign that ticked a moment ago is not pushed again', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Running);
    $broadcast->update(['last_tick_at' => now()]);

    $this->artisan('broadcasts:tick')->assertSuccessful();

    Bus::assertNotDispatched(RunBroadcastJob::class);
});

test('a running campaign with nothing left pending is not pushed again', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Running);
    $broadcast->update(['last_tick_at' => now()->subHour()]);

    BroadcastRecipient::where('broadcast_id', $broadcast->id)
        ->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);

    $this->artisan('broadcasts:tick')->assertSuccessful();

    Bus::assertNotDispatched(RunBroadcastJob::class);
});

test('a scheduled campaign that can no longer start is failed instead of retried every minute', function () {
    Bus::fake();

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = campaignAwaitingTick($user, $connection, Status::Scheduled);
    $broadcast->update(['scheduled_at' => now()->subMinute()]);

    // Everyone was reached (or removed) some other way in the meantime.
    BroadcastRecipient::where('broadcast_id', $broadcast->id)->delete();

    $this->artisan('broadcasts:tick')->assertSuccessful();

    $broadcast->refresh();

    expect($broadcast->status)->toBe(Status::Failed)
        ->and($broadcast->error)->not->toBeNull();
});
