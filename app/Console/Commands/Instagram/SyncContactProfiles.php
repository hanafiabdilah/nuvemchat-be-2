<?php

namespace App\Console\Commands\Instagram;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Profile\ContactProfileSyncer;
use Illuminate\Console\Command;

/**
 * Backfill Instagram contacts still going by their scoped id.
 *
 * The webhook path only re-reads an identity when a message arrives, so
 * threads that went quiet before this existed keep the numeric name they were
 * created with. This asks once for each of them.
 *
 * Contacts are tenant-scoped while an IGSID is scoped to one Instagram
 * account, so a tenant with several accounts is handled by simply trying each
 * of its connections in turn — a contact resolved by one drops out of the next
 * connection's candidate list.
 */
class SyncContactProfiles extends Command
{
    protected $signature = 'instagram:sync-contact-names
        {--connection= : Only this connection id}
        {--limit=500 : Maximum contacts to look up per connection}';

    protected $description = 'Read names and usernames for Instagram contacts still stored under their scoped id';

    public function handle(ContactProfileSyncer $syncer): int
    {
        $connections = Connection::where('channel', Channel::Instagram)
            ->where('status', ConnectionStatus::Active)
            ->when($this->option('connection'), fn ($query, $id) => $query->where('id', $id))
            ->get();

        if ($connections->isEmpty()) {
            $this->warn('No active Instagram connections found.');

            return self::SUCCESS;
        }

        $named = 0;

        foreach ($connections as $connection) {
            $this->line("Connection #{$connection->id} ({$connection->name})");

            $contacts = Contact::where('tenant_id', $connection->tenant_id)
                ->where('channel', Channel::Instagram)
                ->whereNull('username')
                ->limit((int) $this->option('limit'))
                ->get();

            if ($contacts->isEmpty()) {
                $this->line('  nothing to resolve');

                continue;
            }

            foreach ($contacts as $contact) {
                if ($syncer->sync($contact, $connection)) {
                    $named++;
                    $this->line("  {$contact->external_id} → {$contact->fresh()->name}");
                }
            }
        }

        $this->info("Named {$named} contact(s).");

        return self::SUCCESS;
    }
}
