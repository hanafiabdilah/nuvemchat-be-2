<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Jobs\RunBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

/**
 * A draft campaign with $count pasted recipients. Creating one dispatches
 * nothing — only start_now would — so the queue is left real, and the tests
 * that want the pump to actually run just call start/resume.
 */
function draftCampaign($user, $connection, int $count = 6, int $rate = 60): Broadcast
{
    $manual = collect(range(1, $count))
        ->map(fn (int $i) => ['address' => '55119999900' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)])
        ->all();

    $id = test()->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
            'manual_recipients' => $manual,
            'rate_per_minute' => $rate,
        ]))
        ->assertCreated()
        ->json('data.id');

    return Broadcast::findOrFail($id);
}

test('pausing stops the pump at the next batch boundary', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    // 60/min → a batch of 10, so six recipients fit in one batch.
    $broadcast = draftCampaign($user, $connection, 6, 60);
    $broadcast->update(['status' => Status::Running, 'started_at' => now()]);

    // Pausing before the pump ever runs: the job must send nothing at all.
    $this->actingAs($user)->postJson("/api/broadcasts/{$broadcast->id}/pause")->assertOk();

    (new RunBroadcastJob($broadcast->id))->handle(app(\App\Services\Broadcast\BroadcastSender::class));

    expect($broadcast->fresh()->sent_count)->toBe(0);
    expect(BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('status', RecipientStatus::Pending)->count())->toBe(6);
});

test('a paused campaign resumes from where it stopped', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 4);

    // Two already went out before the pause.
    BroadcastRecipient::where('broadcast_id', $broadcast->id)
        ->limit(2)
        ->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
    $broadcast->update(['status' => Status::Paused, 'sent_count' => 2, 'started_at' => now()]);

    $this->actingAs($user)->postJson("/api/broadcasts/{$broadcast->id}/resume")->assertOk();

    $broadcast->refresh();

    expect($broadcast->status)->toBe(Status::Completed)
        ->and($broadcast->sent_count)->toBe(4)
        // Only the two that were still pending were actually sent to.
        ->and(Http::recorded())->toHaveCount(2);
});

test('resuming is refused unless the campaign is paused', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 2);

    $this->actingAs($user)
        ->postJson("/api/broadcasts/{$broadcast->id}/resume")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('cancelling marks everyone still waiting as skipped, and keeps them in the report', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 5);

    BroadcastRecipient::where('broadcast_id', $broadcast->id)
        ->limit(2)
        ->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
    $broadcast->update(['status' => Status::Running, 'sent_count' => 2]);

    $this->actingAs($user)
        ->postJson("/api/broadcasts/{$broadcast->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', Status::Canceled->value)
        ->assertJsonPath('data.skipped', 3)
        ->assertJsonPath('data.pending', 0);

    expect(BroadcastRecipient::where('broadcast_id', $broadcast->id)->count())->toBe(5);
    expect(BroadcastRecipient::where('broadcast_id', $broadcast->id)
        ->where('status', RecipientStatus::Skipped)
        ->where('error', 'Campaign canceled')
        ->count())->toBe(3);
});

test('a cancelled campaign sends nothing more even if a pump job was still queued', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 4);
    $broadcast->update(['status' => Status::Running]);

    $this->actingAs($user)->postJson("/api/broadcasts/{$broadcast->id}/cancel")->assertOk();

    (new RunBroadcastJob($broadcast->id))->handle(app(\App\Services\Broadcast\BroadcastSender::class));

    Http::assertNothingSent();
    expect($broadcast->fresh()->status)->toBe(Status::Canceled);
});

test('retrying failures re-queues only the failures', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 3);

    $recipients = BroadcastRecipient::where('broadcast_id', $broadcast->id)->orderBy('id')->get();
    $recipients[0]->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
    $recipients[1]->update(['status' => RecipientStatus::Failed, 'error' => 'Rate limit hit']);
    // Skipped stays skipped: the reason it was passed over has not changed.
    $recipients[2]->update(['status' => RecipientStatus::Skipped, 'error' => 'Contact opted out of broadcasts']);

    $broadcast->update([
        'status' => Status::Completed,
        'sent_count' => 1,
        'failed_count' => 1,
        'skipped_count' => 1,
        'finished_at' => now(),
    ]);

    $this->actingAs($user)->postJson("/api/broadcasts/{$broadcast->id}/retry-failed")->assertOk();

    $broadcast->refresh();

    expect($broadcast->status)->toBe(Status::Completed)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(0)
        ->and($broadcast->skipped_count)->toBe(1)
        ->and(Http::recorded())->toHaveCount(1);

    expect($recipients[2]->fresh()->status)->toBe(RecipientStatus::Skipped);
});

test('there is nothing to retry when nothing failed', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 2);

    $this->actingAs($user)
        ->postJson("/api/broadcasts/{$broadcast->id}/retry-failed")
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');
});

test('a running campaign cannot be deleted out from under itself', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $broadcast = draftCampaign($user, $connection, 2);
    $broadcast->update(['status' => Status::Running]);

    $this->actingAs($user)
        ->deleteJson("/api/broadcasts/{$broadcast->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $broadcast->update(['status' => Status::Canceled]);

    $this->actingAs($user)->deleteJson("/api/broadcasts/{$broadcast->id}")->assertOk();

    expect(Broadcast::find($broadcast->id))->toBeNull();
    expect(BroadcastRecipient::where('broadcast_id', $broadcast->id)->count())->toBe(0);
});
