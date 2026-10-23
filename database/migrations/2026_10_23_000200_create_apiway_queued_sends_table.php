<?php

use App\Services\Message\ApiwaySendQueue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The work list behind App\Services\Message\ApiwaySendQueue: one row per
     * message the API Way core accepted into its anti-ban queue and has not yet
     * reported sent or failed. Rows are deleted once the outcome is known — the
     * outcome itself lives on the message (`meta.send_queue`).
     *
     * The backfill picks up what the queue has held since it appeared on the
     * core (19 Oct 2026). Without it those messages keep their lone tick even
     * though API Way already knows what happened to them.
     */
    public function up(): void
    {
        Schema::create('apiway_queued_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('queue_id', 64);
            // Null = look again on the next pass. Set to the time the core
            // scheduled the send when that is further away, so a message held
            // until tomorrow is not paged through every minute until then.
            $table->timestamp('check_after')->nullable()->index();
            $table->timestamps();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('apiway_queued_sends');
    }

    private function backfill(): void
    {
        DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.channel', 'whatsapp_proxyhub')
            ->where('messages.sender_type', 'outgoing')
            ->where('messages.created_at', '>=', '2026-10-18 00:00:00')
            ->where('messages.meta', 'like', '%"status":"queued"%')
            ->select('messages.id', 'messages.meta', 'messages.created_at', 'conversations.connection_id')
            ->orderBy('messages.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);
                    $queueId = is_array($meta) ? ApiwaySendQueue::queuedId($meta) : null;

                    if ($queueId === null || isset($meta[ApiwaySendQueue::META_KEY])) {
                        continue;
                    }

                    $meta[ApiwaySendQueue::META_KEY] = ApiwaySendQueue::initialState($queueId);

                    // Without touching updated_at: the sync pass that settles
                    // these is what moves them, and it bumps them then.
                    DB::table('messages')->where('id', $row->id)->update(['meta' => json_encode($meta)]);

                    DB::table('apiway_queued_sends')->insertOrIgnore([
                        'message_id' => $row->id,
                        'connection_id' => $row->connection_id,
                        'queue_id' => $queueId,
                        'check_after' => null,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
