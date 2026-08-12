<?php

namespace App\Services\Broadcast;

use App\Enums\Broadcast\AddressType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Events\BroadcastProgress;
use App\Jobs\RunBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a campaign can be told to do. The controller validates and
 * authorises; this decides what those verbs actually mean.
 */
class BroadcastService
{
    /** Colour the campaign tag is created with, so blasts are recognisable in the inbox. */
    private const TAG_COLOR = '#7c3aed';

    /**
     * Turn picked contacts and pasted addresses into one de-duplicated recipient
     * list, and write it alongside the campaign.
     *
     * De-duplication is the whole reason this is a single step: the same person
     * routinely arrives twice — once from the contact book, once in a pasted
     * list — and messaging them twice is the most visible way a campaign can
     * embarrass a tenant.
     *
     * @param  array<int, int|string>  $contactIds
     * @param  array<int, array{address: string, name?: string|null}>  $manual
     */
    public function createRecipients(Broadcast $broadcast, Connection $connection, array $contactIds, array $manual): int
    {
        $addressType = $connection->channel->broadcastAddressType();
        $rows = [];

        foreach ($this->contactsFor($connection, $contactIds) as $contact) {
            $this->collect($rows, $addressType, $contact->external_id, $contact->name, $contact->id);
        }

        if ($manual !== [] && ! $addressType->acceptsManualInput()) {
            throw ValidationException::withMessages([
                'manual_recipients' => 'This channel addresses people by an internal id, so recipients can only be chosen from existing contacts.',
            ]);
        }

        foreach ($manual as $entry) {
            $this->collect($rows, $addressType, (string) ($entry['address'] ?? ''), $entry['name'] ?? null);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'recipients' => 'No valid recipients — check the numbers you pasted.',
            ]);
        }

        $now = now();

        BroadcastRecipient::insert(array_map(fn (array $row) => $row + [
            'broadcast_id' => $broadcast->id,
            'status' => RecipientStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($rows)));

        $broadcast->update(['total_recipients' => count($rows)]);

        return count($rows);
    }

    /**
     * Add one address to the pile, keyed by its normalised form so the second
     * sighting of the same person is dropped rather than inserted. First sighting
     * wins: a contact picked deliberately keeps its contact_id even if the same
     * number turns up again in a pasted list.
     *
     * @param  array<string, array{contact_id: int|null, address: string, name: string|null}>  $rows
     */
    private function collect(array &$rows, AddressType $addressType, string $address, ?string $name, ?int $contactId = null): void
    {
        $normalized = $addressType->normalize($address);

        if (! $addressType->isValid($normalized) || isset($rows[$normalized])) {
            return;
        }

        $rows[$normalized] = [
            'contact_id' => $contactId,
            'address' => $normalized,
            'name' => $name ?: null,
        ];
    }

    /**
     * @param  array<int, int|string>  $contactIds
     * @return Collection<int, Contact>
     */
    private function contactsFor(Connection $connection, array $contactIds): Collection
    {
        if ($contactIds === []) {
            return collect();
        }

        return Contact::where('tenant_id', $connection->tenant_id)
            ->whereIn('id', $contactIds)
            // A group is a chat, not a person, and an opted-out contact asked
            // not to be here. Both are filtered rather than rejected: the
            // dashboard lets you select all of a filter, and one stale row in
            // the selection should not fail the whole campaign.
            ->where('is_group', false)
            ->whereNull('broadcast_opted_out_at')
            ->get();
    }

    /** Begin sending now. */
    public function start(Broadcast $broadcast): Broadcast
    {
        if ($broadcast->status->isActive()) {
            return $broadcast;
        }

        if ($broadcast->status->isFinished()) {
            throw ValidationException::withMessages([
                'status' => 'This campaign has already finished.',
            ]);
        }

        if ($broadcast->pendingCount() === 0) {
            throw ValidationException::withMessages([
                'recipients' => 'There is nobody left to send to.',
            ]);
        }

        $broadcast->update([
            'status' => Status::Running,
            'tag_id' => $broadcast->tag_id ?? $this->campaignTag($broadcast)->id,
            'started_at' => $broadcast->started_at ?? now(),
            'finished_at' => null,
            'last_tick_at' => now(),
            'error' => null,
        ]);

        RunBroadcastJob::dispatch($broadcast->id);
        broadcast(new BroadcastProgress($broadcast));

        return $broadcast;
    }

    /** Hand the campaign to the scheduler instead of starting it now. */
    public function schedule(Broadcast $broadcast): Broadcast
    {
        $broadcast->update(['status' => Status::Scheduled]);
        broadcast(new BroadcastProgress($broadcast));

        return $broadcast;
    }

    /**
     * Stop after the batch already in flight. Up to one batch of messages can
     * still go out after this returns — the pump only re-reads the status
     * between batches, and killing a send mid-request would leave a message the
     * platform accepted with no row to show for it.
     */
    public function pause(Broadcast $broadcast): Broadcast
    {
        if (! $broadcast->status->isActive()) {
            throw ValidationException::withMessages([
                'status' => 'Only a running campaign can be paused.',
            ]);
        }

        $broadcast->update(['status' => Status::Paused]);
        broadcast(new BroadcastProgress($broadcast));

        return $broadcast;
    }

    public function resume(Broadcast $broadcast): Broadcast
    {
        if ($broadcast->status !== Status::Paused) {
            throw ValidationException::withMessages([
                'status' => 'Only a paused campaign can be resumed.',
            ]);
        }

        return $this->start($broadcast);
    }

    /**
     * Give up on the rest. Everyone still waiting is marked skipped rather than
     * deleted, so the report keeps saying how many people the campaign chose not
     * to reach.
     */
    public function cancel(Broadcast $broadcast): Broadcast
    {
        if ($broadcast->status->isFinished()) {
            return $broadcast;
        }

        $skipped = DB::transaction(function () use ($broadcast) {
            return BroadcastRecipient::where('broadcast_id', $broadcast->id)
                ->whereIn('status', [RecipientStatus::Pending, RecipientStatus::Sending])
                ->update([
                    'status' => RecipientStatus::Skipped,
                    'error' => 'Campaign canceled',
                    'updated_at' => now(),
                ]);
        });

        // Raw increment rather than a read-modify-write: a batch that was still
        // in flight when cancel landed is writing these same counters.
        Broadcast::whereKey($broadcast->id)->update([
            'status' => Status::Canceled->value,
            'skipped_count' => DB::raw('skipped_count + ' . $skipped),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        broadcast(new BroadcastProgress($broadcast->refresh()));

        return $broadcast;
    }

    /**
     * Put the failures back in the queue and run again. Skipped recipients are
     * left alone on purpose: they were passed over for a reason that has not
     * changed (opted out, window shut), and retrying them would just re-skip.
     */
    public function retryFailed(Broadcast $broadcast): Broadcast
    {
        $retried = BroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->where('status', RecipientStatus::Failed)
            ->update([
                'status' => RecipientStatus::Pending,
                'error' => null,
                'updated_at' => now(),
            ]);

        if ($retried === 0) {
            throw ValidationException::withMessages([
                'recipients' => 'There are no failed recipients to retry.',
            ]);
        }

        $broadcast->update([
            'failed_count' => max(0, $broadcast->failed_count - $retried),
            'status' => Status::Paused,
        ]);

        return $this->start($broadcast->refresh());
    }

    /**
     * The tag every conversation this campaign touches gets stamped with, so an
     * agent can find (or clear) the whole blast afterwards. Re-used by name, so
     * re-running a campaign does not litter the tag list with near-duplicates.
     */
    private function campaignTag(Broadcast $broadcast): Tag
    {
        return Tag::firstOrCreate(
            [
                'tenant_id' => $broadcast->tenant_id,
                'name' => $broadcast->name,
            ],
            ['color' => self::TAG_COLOR],
        );
    }
}
