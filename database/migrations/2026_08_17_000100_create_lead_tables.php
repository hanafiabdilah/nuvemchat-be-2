<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sales funnel: which people might buy, and how far along each one is.
     *
     * A lead is deliberately none of the three things that already exist. It is
     * not the contact (that is who they are, forever), and it is not the
     * conversation (that is one episode, which dies at Resolved — a message
     * arriving afterwards opens a brand new row). A lead is one *attempt to
     * sell*, and it has to outlive any single thread: the same person asking
     * for a price, going quiet, and coming back a week later is one sale, not
     * three.
     *
     * So the shape is: one contact has many leads over their lifetime, and one
     * lead gathers many conversations.
     */
    public function up(): void
    {
        Schema::create('lead_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Where auto-created leads land. Exactly one per tenant is true;
            // enforced by LeadPipeline::makeDefault() rather than a constraint,
            // because "flip which one is default" is two writes and a unique
            // index would reject the intermediate state.
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('lead_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('lead_pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 32)->default('gray');
            // `open` | `won` | `lost`. This — not the name — is what the reports
            // read, so a tenant renaming "Cliente" to "Fechado" or running the
            // board in another language never changes what counts as a sale.
            $table->string('kind')->default('open');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['pipeline_id', 'position']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: deleting a stage that still holds cards has to
            // fail loudly. Silently orphaning them would leave leads that no
            // column can render and no report can classify.
            $table->foreignId('pipeline_id')->constrained('lead_pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('lead_stages')->restrictOnDelete();
            // The responsible agent. Null is a real state — nobody has picked
            // this one up yet — and it survives the agent's account being
            // deleted, because the sale is the workspace's, not theirs.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // Which channel it came in on. Not used for access control today
            // (see the LeadUpdated event for why), but recording it is what
            // makes per-connection scoping buildable later without a migration.
            $table->foreignId('source_connection_id')->nullable()->constrained('connections')->nullOnDelete();

            $table->string('title')->nullable(); // null = show the contact's name
            $table->decimal('value', 14, 2)->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->string('status')->default('open');   // open | won | lost
            $table->string('source')->default('inbound'); // inbound | manual | broadcast | import

            $table->string('temperature')->default('cold'); // cold | warm | hot
            $table->unsignedTinyInteger('temperature_score')->default(0);

            // When the customer last wrote. Drives the score, and answers "how
            // long has this been quiet" without re-scanning messages.
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'stage_id']);
            $table->index(['tenant_id', 'owner_id', 'status']);
            $table->index(['tenant_id', 'temperature', 'status']);
        });

        // The invariant, enforced by the database rather than by every writer.
        //
        // At most ONE open lead per contact. That is what makes "which card does
        // this incoming message belong to?" have exactly one answer, always —
        // the question that turns messaging CRMs into guesswork otherwise. Past
        // leads are unlimited; they are all won or lost, so they drop out of the
        // index automatically.
        //
        // A generated column rather than a partial unique index because MySQL
        // has no `WHERE status = 'open'` on an index. It cannot drift, because
        // no application code ever writes it.
        //
        // ⚠️ SQLite cannot ADD a stored generated column via ALTER, so this has
        // to be born with the table — never move it to a later migration.
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('open_contact_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'open' THEN contact_id END");
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unique('open_contact_id');
        });

        Schema::create('lead_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Denormalised so StatsScope can filter the log without joining
            // back through leads on every report query.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('lead_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('lead_stages')->nullOnDelete();
            // Snapshot of where it landed. The FK can go null if the stage is
            // deleted years later; an audit log that forgets its own content is
            // not an audit log.
            $table->string('to_stage_name');
            // Null means the system moved it, not that the agent was deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // No updated_at: these rows are written once and never touched.
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_stage_events');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_stages');
        Schema::dropIfExists('lead_pipelines');
    }
};
