<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The old sales funnel goes, to make room for the CRM (customers, companies,
 * leads, deals, tasks), which is built from scratch in the migrations after
 * this one — and the outbound webhooks go with it, since their only events
 * were funnel events. Production held only cards opened automatically from
 * conversations (and one API test), so nothing a person typed is lost.
 *
 * Goes: the funnel tables (pipelines, stages, leads, stage history, API
 * intakes), `conversations.lead_id`, `tenants.lead_settings`, the webhook
 * endpoints and their delivery log, the `leads.*`, `lead-pipelines.manage` and
 * `webhooks.manage` permissions, the `mcp:leads.*` scopes, and any queued job
 * of a class that no longer exists. The `crm` plan feature stays: the CRM is
 * sold under the same key.
 *
 * ⚠️ Everything else is left exactly as it was, and the order below is what
 * guarantees it:
 *
 *  - Every foreign key between the funnel and the rest of the schema points
 *    *from* the funnel (to tenants, contacts, users, connections, api_keys,
 *    conversations). Dropping a funnel table never deletes a row anywhere else.
 *    The one link the other way, `conversations.lead_id`, is nullable and is
 *    dropped as a column — its constraint first — before `leads` goes, so no
 *    conversation is touched beyond losing that column.
 *  - Flows keep working. A Lead node did its job and always carried on to its
 *    one output, so each one is cut out of its flow: whatever led into it now
 *    leads to where it led, and a conversation parked on it moves there too.
 *    Without this a saved flow holding one would no longer load, because the
 *    node type is gone.
 *  - JSON columns lose nothing but the two lead scopes in MCP grants.
 *  - `webhook_events` (inbound webhook dedupe) is a different table and stays.
 *  - ⚠️ SQLite (local and test databases) cannot drop a foreign key in place:
 *    Laravel rebuilds `conversations` — copy, drop, rename — with foreign keys
 *    switched off for the duration. That switch is ignored inside a
 *    transaction, and then dropping the old table cascades into `messages`,
 *    `flow_states` and everything else hanging off a conversation. So this
 *    migration runs outside a transaction, and refuses outright if it finds
 *    itself inside one. MySQL drops the constraint and the column in place and
 *    is unaffected either way.
 *
 * Irreversible: the funnel and the delivery log are gone.
 */
return new class extends Migration
{
    /** See the SQLite note above. */
    public $withinTransaction = false;

    private const PERMISSIONS = [
        'leads.view', 'leads.create', 'leads.update', 'leads.delete',
        'lead-pipelines.manage', 'webhooks.manage',
    ];

    /** Children before parents, so no drop waits on a constraint. */
    private const TABLES = [
        'lead_intakes', 'lead_stage_events', 'leads', 'lead_stages', 'lead_pipelines',
        'webhook_deliveries', 'webhook_endpoints',
    ];

    private const MCP_SCOPES = ['mcp:leads.read', 'mcp:leads.write'];

    /** Queued jobs whose class this release deletes; they could only fail. */
    private const DEAD_JOBS = ['EnsureLeadForConversation', 'DeliverWebhook'];

    public function up(): void
    {
        // Checked first, so a refused run has changed nothing.
        if (Schema::hasColumn('conversations', 'lead_id')
            && DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'Refusing to rebuild `conversations` on SQLite inside a transaction: '
                .'the copy-and-drop would cascade into every message. Run this migration on its own.'
            );
        }

        $this->retireLeadNodes();

        $this->dropConversationLeadColumn();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('tenants', 'lead_settings')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('lead_settings');
            });
        }

        $this->dropPermissions();

        $this->stripMcpScopes('mcp_connections');
        $this->stripMcpScopes('mcp_authorization_codes');

        $this->dropDeadJobs();
    }

    public function down(): void
    {
        // Irreversible — see the class note.
    }

    /**
     * Cut every Lead node out of its flow.
     *
     * One node at a time, re-reading after each: a lead node pointing at
     * another lead node resolves on the next pass, at the second node's own
     * successor. Bounded, so a graph nobody could have drawn still ends.
     */
    private function retireLeadNodes(): void
    {
        if (! Schema::hasTable('flow_nodes')) {
            return;
        }

        for ($pass = 0; $pass < 100000; $pass++) {
            $nodeId = DB::table('flow_nodes')->where('type', 'lead')->orderBy('id')->value('id');

            if ($nodeId === null) {
                return;
            }

            // The engine follows the first edge by id; so does this.
            $next = DB::table('flow_edges')
                ->where('source_node_id', $nodeId)
                ->where('target_node_id', '!=', $nodeId)
                ->orderBy('id')
                ->value('target_node_id');

            $incoming = DB::table('flow_edges')
                ->where('target_node_id', $nodeId)
                ->where('source_node_id', '!=', $nodeId);

            if ($next !== null) {
                // The edge keeps its source, its output and its id — only
                // where it arrives changes — so which branch it is, and its
                // precedence among duplicates, stay what they were.
                $incoming->update(['target_node_id' => $next, 'updated_at' => now()]);

                DB::table('flow_states')
                    ->where('current_node_id', $nodeId)
                    ->update(['current_node_id' => $next, 'updated_at' => now()]);
            } else {
                // The flow ended after this node; it now ends where it was
                // reached, which the engine treats the same way.
                $incoming->delete();
            }

            DB::table('flow_edges')
                ->where('source_node_id', $nodeId)
                ->orWhere('target_node_id', $nodeId)
                ->delete();

            // Anything still pointing at the node (a parked flow with nowhere
            // to go, a payment or AI run log) is nulled by its own foreign key.
            DB::table('flow_nodes')->where('id', $nodeId)->delete();
        }

        throw new RuntimeException('Could not retire every Lead flow node: the graph did not converge.');
    }

    private function dropConversationLeadColumn(): void
    {
        if (! Schema::hasColumn('conversations', 'lead_id')) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });
    }

    private function dropPermissions(): void
    {
        $tables = config('permission.table_names');
        $ids = DB::table($tables['permissions'])->whereIn('name', self::PERMISSIONS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table($tables['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['model_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['permissions'])->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function stripMcpScopes(string $table): void
    {
        if (! Schema::hasColumn($table, 'scopes')) {
            return;
        }

        DB::table($table)
            ->where('scopes', 'like', '%mcp:leads.%')
            ->orderBy('id')
            ->select(['id', 'scopes'])
            ->each(function (object $row) use ($table) {
                $scopes = json_decode((string) $row->scopes, true);

                if (! is_array($scopes)) {
                    return;
                }

                $kept = array_values(array_diff($scopes, self::MCP_SCOPES));

                DB::table($table)->where('id', $row->id)->update(['scopes' => json_encode($kept)]);
            });
    }

    private function dropDeadJobs(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        foreach (self::DEAD_JOBS as $class) {
            // The payload names the class as "displayName":"App\\Jobs\\<Class>";
            // matching around the backslashes keeps the pattern the same on
            // MySQL (where `\` escapes in LIKE) and SQLite.
            DB::table('jobs')->where('payload', 'like', '%"displayName":"App%Jobs%'.$class.'"%')->delete();
        }
    }
};
