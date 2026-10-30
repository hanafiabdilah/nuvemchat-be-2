<?php

namespace App\Console\Commands\Crm;

use App\Models\Contact;
use App\Services\Crm\CustomerResolver;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Give every channel contact its CRM customer.
 *
 * New contacts are linked the moment they are created (ContactObserver); this
 * covers the ones that existed before the CRM, and any created by a path that
 * fires no model events. Hourly, a bounded batch at a time, so the first runs
 * after deploy work through an old contact book without a maintenance step —
 * and safe to run by hand (`--limit=0` for all of it).
 */
class LinkContacts extends Command
{
    protected $signature = 'crm:link-contacts {--limit=5000 : Contacts per run (0 = all)} {--dry-run}';

    protected $description = 'Link channel contacts that have no CRM customer yet';

    public function handle(CustomerResolver $resolver): int
    {
        Heartbeat::ping('crm:link-contacts');

        $limit = (int) $this->option('limit');
        $query = Contact::query()->whereNull('customer_id')->where('is_group', false)->orderBy('id');

        $pending = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$pending} contact(s) without a customer.");

            return self::SUCCESS;
        }

        $linked = 0;

        $query->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get()
            ->each(function (Contact $contact) use ($resolver, &$linked) {
                if ($resolver->attach($contact)) {
                    $linked++;
                }
            });

        $this->info("Linked {$linked} of {$pending} contact(s).");

        return self::SUCCESS;
    }
}
