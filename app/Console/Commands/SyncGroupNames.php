<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Models\Contact;
use App\Services\Conversation\Group\ApiwayGroupClient;
use App\Services\Conversation\Group\GroupMetadataSyncer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill group names that were never learned.
 *
 * Group subjects normally arrive on a push (GroupInfo / JoinedGroup webhooks),
 * which only fire on a rename or a join — so every group that already existed
 * when its instance was linked still carries the JID placeholder. One call to
 * /v1/group/get-all-groups per connection names all of them at once.
 */
class SyncGroupNames extends Command
{
    protected $signature = 'groups:sync-names
        {--connection= : Only this connection id}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Read WhatsApp group subjects from the API Way core and fix placeholder names';

    public function handle(ApiwayGroupClient $client, GroupMetadataSyncer $syncer): int
    {
        $connections = Connection::where('channel', Channel::WhatsappApiway)
            ->when($this->option('connection'), fn ($query, $id) => $query->where('id', $id))
            ->get();

        if ($connections->isEmpty()) {
            $this->warn('No API Way connections found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $renamed = 0;

        foreach ($connections as $connection) {
            $this->line("Connection #{$connection->id} ({$connection->name})");

            try {
                $groups = $client->allGroups($connection);
            } catch (Throwable $e) {
                $this->error('  lookup failed: ' . $e->getMessage());

                continue;
            }

            if ($groups === []) {
                $this->line('  no groups returned');

                continue;
            }

            // Only groups we actually have a thread for are worth touching —
            // the core lists every group the phone belongs to, most of which
            // have never appeared in the inbox.
            $contacts = Contact::where('tenant_id', $connection->tenant_id)
                ->where('is_group', true)
                ->whereIn('external_id', array_keys($groups))
                ->get();

            foreach ($contacts as $contact) {
                $name = $groups[$contact->external_id];

                if ($contact->name === $name) {
                    continue;
                }

                if ($contact->name_locked) {
                    $this->line("  skip (renamed by an agent): {$contact->name}");

                    continue;
                }

                $this->line("  {$contact->external_id}: \"{$contact->name}\" → \"{$name}\"");

                if (! $dryRun && $syncer->apply($connection, $contact, $name)) {
                    $contact->forceFill(['group_synced_at' => now()])->save();
                    $renamed++;
                }
            }
        }

        $this->info($dryRun ? 'Dry run complete.' : "Renamed {$renamed} group(s).");

        return self::SUCCESS;
    }
}
