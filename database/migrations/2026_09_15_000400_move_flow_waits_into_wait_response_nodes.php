<?php

use App\Services\Flow\LegacyWaitUpgrade;
use App\Services\Flow\WaitResponseNodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves every stored flow off the two ways of waiting that no longer exist —
 * the Message node's `wait_for_reply` switch and the Response node's no-reply
 * limit — and onto the Wait for reply node, without any flow behaving
 * differently. The graph rewrite itself is {@see LegacyWaitUpgrade}; what lives
 * here is persisting it and keeping conversations that are mid-flow in place.
 *
 * Conversations already parked are the delicate part:
 *
 *  - Parked on the node after a waiting Message node (the switch put the flow
 *    there without running it): nothing to do. The new wait sits *before* that
 *    node, and a node reached without running still runs on the customer's
 *    next message.
 *
 *  - Parked on a Response node with a limit, question already sent: the node
 *    keeps its id and becomes the wait, so the flag and the timer token move to
 *    the wait's keys. A timer job already queued names the old job class,
 *    which is kept to forward to the new timeout.
 *
 *  - On that Response node without having run it (a waiting Message node put
 *    the flow there): moved back to the inserted Message node, so the question
 *    still goes out before anything is waited for.
 *
 * Irreversible: the old fields are gone from the code that read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $flowIds = DB::table('flow_nodes')
            ->whereIn('type', ['message', 'response'])
            ->distinct()
            ->pluck('flow_id');

        $rewritten = 0;

        foreach ($flowIds as $flowId) {
            $rows = DB::table('flow_nodes')->where('flow_id', $flowId)->orderBy('id')->get();
            $nodeIds = $rows->pluck('id')->all();

            $nodes = $rows->map(fn ($row) => [
                'key' => (string) $row->id,
                'type' => $row->type,
                'data' => $row->data === null ? null : json_decode($row->data, true),
                'position_x' => (int) $row->position_x,
                'position_y' => (int) $row->position_y,
            ])->all();

            $edges = DB::table('flow_edges')
                ->whereIn('source_node_id', $nodeIds)
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'source_key' => (string) $row->source_node_id,
                    'target_key' => (string) $row->target_node_id,
                    'condition_value' => $row->condition_value,
                ])
                ->all();

            $result = LegacyWaitUpgrade::upgrade($nodes, $edges);

            if (!$result['changed']) {
                continue;
            }

            DB::transaction(function () use ($flowId, $nodeIds, $result) {
                $now = now();
                $existing = array_flip(array_map('strval', $nodeIds));
                $ids = [];

                foreach ($result['nodes'] as $node) {
                    $row = [
                        'type' => $node['type'],
                        'data' => $node['data'] === null ? null : json_encode($node['data']),
                        'position_x' => (int) $node['position_x'],
                        'position_y' => (int) $node['position_y'],
                        'updated_at' => $now,
                    ];

                    if (isset($existing[$node['key']])) {
                        $ids[$node['key']] = (int) $node['key'];
                        DB::table('flow_nodes')->where('id', (int) $node['key'])->update($row);
                        continue;
                    }

                    $ids[$node['key']] = DB::table('flow_nodes')->insertGetId($row + [
                        'flow_id' => $flowId,
                        'created_at' => $now,
                    ]);
                }

                DB::table('flow_edges')->whereIn('source_node_id', $nodeIds)->delete();

                foreach ($result['edges'] as $edge) {
                    if (!isset($ids[$edge['source_key']], $ids[$edge['target_key']])) {
                        continue;
                    }

                    DB::table('flow_edges')->insert([
                        'source_node_id' => $ids[$edge['source_key']],
                        'target_node_id' => $ids[$edge['target_key']],
                        'condition_value' => $edge['condition_value'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($result['questions'] as $responseKey => $questionKey) {
                    $this->keepParkedConversations((int) $responseKey, $ids[$questionKey]);
                }
            });

            $rewritten++;
        }

        Log::info('Flow waits moved into Wait for reply nodes', ['flows' => $rewritten]);
    }

    public function down(): void
    {
        // Irreversible: see the class docblock.
    }

    private function keepParkedConversations(int $responseId, int $questionId): void
    {
        $states = DB::table('flow_states')
            ->where('current_node_id', $responseId)
            ->where('status', 'running')
            ->get();

        foreach ($states as $state) {
            $data = json_decode($state->state_data ?? '[]', true) ?: [];
            $sentKey = "_response_sent_{$responseId}";

            if (!array_key_exists($sentKey, $data)) {
                DB::table('flow_states')->where('id', $state->id)->update(['current_node_id' => $questionId]);
                continue;
            }

            unset($data[$sentKey]);
            $data[WaitResponseNodes::parkedKey($responseId)] = (int) DB::table('messages')
                ->where('conversation_id', $state->conversation_id)
                ->max('id');

            $timerKey = "_response_timeout_{$responseId}";

            if (array_key_exists($timerKey, $data)) {
                $data[WaitResponseNodes::timeoutKey($responseId)] = $data[$timerKey];
                unset($data[$timerKey]);
            }

            DB::table('flow_states')->where('id', $state->id)->update(['state_data' => json_encode($data)]);
        }
    }
};
