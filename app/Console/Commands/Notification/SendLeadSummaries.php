<?php

namespace App\Console\Commands\Notification;

use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\SenderType;
use App\Enums\Notification\NotificationType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Sale;
use App\Models\User;
use App\Services\Notification\WhatsappAlerts;
use App\Support\Heartbeat;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The new-leads summary people opt into for their WhatsApp: every hour (when
 * something came in), or every morning for the day before.
 *
 * A lead is a contact's first conversation on a connection in which they
 * actually wrote — a customer coming back next week is not a new lead, and a
 * site-widget session nobody typed into is not one at all. Counted only on the
 * connections the person can reach, and in the workspace's own timezone, so
 * "yesterday" means what the person reading it means by it.
 */
class SendLeadSummaries extends Command
{
    protected $signature = 'alerts:lead-summary';

    protected $description = 'Send the hourly and daily new-leads summaries people asked for on WhatsApp';

    public function handle(): int
    {
        Heartbeat::ping('alerts:lead-summary');

        $sent = 0;

        User::whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_number')
            ->whereNotNull('notification_preferences')
            ->whereNotNull('tenant_id')
            ->with('tenant.market')
            ->get()
            ->filter(fn (User $user) => $user->tenant && WhatsappAlerts::wants($user, WhatsappAlerts::LEAD_SUMMARY))
            ->each(function (User $user) use (&$sent) {
                if ($this->summarise($user)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} lead summary(ies).");

        return self::SUCCESS;
    }

    private function summarise(User $user): bool
    {
        $mode = $user->notificationSettings()['whatsapp_alerts'][WhatsappAlerts::LEAD_SUMMARY];
        $timezone = $user->tenant->displayTimezone();
        $now = now()->timezone($timezone);

        if ($mode === WhatsappAlerts::SUMMARY_DAILY) {
            if ($now->hour !== WhatsappAlerts::DAILY_SUMMARY_HOUR) {
                return false;
            }

            $to = $now->copy()->startOfDay();
            $from = $to->copy()->subDay();
        } else {
            $to = $now->copy()->startOfHour();
            $from = $to->copy()->subHour();
        }

        // Once per person and period, however often the scheduler fires.
        if (! Cache::add("alert:lead-summary:{$user->id}:{$mode}:{$from->timestamp}", true, now()->addDays(2))) {
            return false;
        }

        $connections = Connection::where('tenant_id', $user->tenant_id)
            ->get()
            ->filter(fn (Connection $connection) => $user->canAccessConnection($connection))
            ->keyBy('id');

        $leads = $this->leadsByConnection($connections->keys()->all(), $from, $to);
        $total = array_sum($leads);

        // An hour with nothing in it is not news; a day with nothing in it is.
        if ($mode === WhatsappAlerts::SUMMARY_HOURLY && $total === 0) {
            return false;
        }

        $locale = $user->localeCode();

        return WhatsappAlerts::send(NotificationType::AlertLeadSummary, $user, [
            'period' => $mode === WhatsappAlerts::SUMMARY_DAILY
                ? __('alerts.period_day', ['date' => $from->copy()->locale($locale)->isoFormat('D MMM')], $locale)
                : __('alerts.period_hour', ['from' => $from->format('H:i'), 'to' => $to->format('H:i')], $locale),
            'leads' => (string) $total,
            'details' => $this->details($leads, $connections->all(), $user, $from, $to, $locale),
        ]);
    }

    /**
     * New leads per connection in [from, to).
     *
     * @param  list<int>  $connectionIds
     * @return array<int, int> connection id => leads
     */
    private function leadsByConnection(array $connectionIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($connectionIds === []) {
            return [];
        }

        return Conversation::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('created_at', '>=', $from->copy()->utc())
            ->where('created_at', '<', $to->copy()->utc())
            ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', ConversationType::Group->value))
            ->whereHas('messages', fn ($query) => $query->where('sender_type', SenderType::Incoming))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('conversations as earlier')
                ->whereColumn('earlier.contact_id', 'conversations.contact_id')
                ->whereColumn('earlier.connection_id', 'conversations.connection_id')
                ->whereColumn('earlier.id', '<', 'conversations.id'))
            ->selectRaw('connection_id, count(*) as total')
            ->groupBy('connection_id')
            ->pluck('total', 'connection_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * One line per connection that had leads, then the sales, if any.
     *
     * @param  array<int, int>  $leads
     * @param  array<int, Connection>  $connections
     */
    private function details(array $leads, array $connections, User $user, CarbonInterface $from, CarbonInterface $to, string $locale): string
    {
        $lines = [];
        arsort($leads);

        if (count($connections) > 1) {
            foreach (array_slice($leads, 0, 5, true) as $connectionId => $count) {
                $lines[] = __('alerts.details_connection', ['connection' => ($connections[$connectionId] ?? null)?->name ?? '#'.$connectionId, 'count' => $count], $locale);
            }
        }

        $currency = $user->tenant->currency();
        $sales = Sale::where('tenant_id', $user->tenant_id)
            ->whereIn('connection_id', array_keys($connections))
            ->where('currency', $currency)
            ->where('sold_at', '>=', $from->copy()->utc())
            ->where('sold_at', '<', $to->copy()->utc())
            ->selectRaw('count(*) as total, coalesce(sum(amount_cents), 0) as amount')
            ->first();

        if ($sales && (int) $sales->total > 0) {
            $lines[] = __('alerts.details_sales', ['count' => (int) $sales->total, 'amount' => Money::format((int) $sales->amount, $currency)], $locale);
        }

        return $lines === [] ? '' : "\n\n".implode("\n", $lines);
    }
}
