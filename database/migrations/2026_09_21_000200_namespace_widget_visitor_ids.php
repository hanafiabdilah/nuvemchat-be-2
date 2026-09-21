<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move existing widget contacts into the id space the code now writes to.
 *
 * `visitor_id` is the only contact id a visitor chooses, and it used to be
 * stored raw — sharing one namespace with every phone number in the workspace.
 * App\Services\Contact\WidgetVisitorId prefixes it from now on; without this, a
 * returning visitor whose browser still holds the old id would be treated as
 * somebody new, and the ghost-contact problem the funnel already has would get
 * worse.
 *
 * ⚠️ Scoped to `channel = live_chat_widget` on purpose. A contact that was
 * *poisoned* — created as WhatsApp, then reached through the widget — is stored
 * as a WhatsApp contact and must stay one: it is the real customer's record,
 * and moving it into the widget namespace would strand their history.
 *
 * ⚠️ The prefix is spelled out here rather than read from WidgetVisitorId. A
 * migration is a record of what happened on a particular day; if that class is
 * ever changed, this file must keep meaning what it meant when it ran.
 *
 * ⚠️ String concatenation is spelled differently per driver — production is
 * MySQL, the test suite is SQLite — so it is built rather than written once.
 */
return new class extends Migration
{
    private const CHANNEL = 'live_chat_widget';

    public function up(): void
    {
        // Per connection, not one bulk UPDATE: the prefix carries the
        // connection id, and a tenant may run several widgets.
        $connections = DB::table('connections')
            ->where('channel', self::CHANNEL)
            ->orderBy('id')
            ->get(['id', 'tenant_id']);

        foreach ($connections as $connection) {
            $prefix = 'w'.$connection->id.':';

            // Contacts belong to the tenant, not to the connection, so the
            // channel is what narrows this to the widget's own rows.
            DB::table('contacts')
                ->where('tenant_id', $connection->tenant_id)
                ->where('channel', self::CHANNEL)
                ->where('external_id', 'not like', $prefix.'%')
                ->update(['external_id' => $this->prepend($prefix, 'external_id')]);

            // The conversation carries the same id. Nothing reads it on this
            // channel — the widget addresses its session by token — but leaving
            // the two disagreeing would be a trap for whoever reads a row next.
            DB::table('conversations')
                ->where('connection_id', $connection->id)
                ->whereNotNull('external_id')
                ->where('external_id', 'not like', $prefix.'%')
                ->update(['external_id' => $this->prepend($prefix, 'external_id')]);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Stripping the prefix would put visitor ids
        // back in the same namespace as phone numbers, which is the whole
        // reason this ran.
    }

    /** `{$prefix}{$column}`, in whichever dialect this connection speaks. */
    private function prepend(string $prefix, string $column): \Illuminate\Database\Query\Expression
    {
        $quoted = DB::getPdo()->quote($prefix);

        return DB::raw(DB::connection()->getDriverName() === 'sqlite'
            ? "{$quoted} || {$column}"
            : "CONCAT({$quoted}, {$column})");
    }
};
