<?php

namespace App\Services\Flow;

use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSplitAssignment;
use App\Models\FlowState;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where split test conversations go, and how each variant is doing.
 *
 * The node's own rules — modes, weights, limits — are in SplitTestNodes and
 * touch no database. This is the part that reads and writes the counts.
 */
final class SplitTests
{
    /**
     * Deal this conversation to one of the candidate variants and record it.
     *
     * A conversation that already went through this test keeps its variant: a
     * flow restarted by hand, or one that loops back to the split, would
     * otherwise send the same customer into a second funnel and count them
     * twice. Only within the current run of the test — after "restart" it is
     * a new test, and it deals afresh.
     *
     * Count and insert happen under one lock. Without it, two conversations
     * arriving together both read the same counts, both go to the variant
     * that is behind, and a sequence overshoots its limit by however many
     * arrived at once — which, for a funnel fed by ads, is most of them.
     *
     * @param  list<array{id: string, label: string, weight: int, limit: int|null}>  $candidates  the variants with an edge
     */
    public static function assign(FlowState $flowState, FlowNode $node, array $candidates): ?string
    {
        $ids = array_column($candidates, 'id');

        if ($ids === []) {
            return null;
        }

        $data = (array) ($node->data ?? []);
        $since = SplitTestNodes::startedAt($data);
        $flowId = (int) $node->flow_id;
        $conversation = $flowState->conversation;

        $deal = function () use ($data, $since, $flowId, $conversation, $candidates, $ids, $node): ?string {
            $previous = self::window($flowId, SplitTestNodes::branches($data), $since)
                ->where('flow_split_assignments.conversation_id', $conversation->id)
                ->orderByDesc('flow_split_assignments.id')
                ->value('variant_id');

            if (is_string($previous) && in_array($previous, $ids, true)) {
                return $previous;
            }

            $counts = self::window($flowId, $ids, $since)
                ->selectRaw('variant_id, count(*) as total')
                ->groupBy('variant_id')
                ->pluck('total', 'variant_id')
                ->map(fn ($total) => (int) $total)
                ->all();

            $chosen = SplitTestNodes::choose($candidates, $counts, SplitTestNodes::mode($data));

            if ($chosen === null) {
                return null;
            }

            FlowSplitAssignment::create([
                'tenant_id' => $conversation->connection->tenant_id,
                'flow_id' => $flowId,
                'flow_node_id' => $node->id,
                'variant_id' => $chosen,
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'last_message_id' => (int) Message::where('conversation_id', $conversation->id)->max('id'),
            ]);

            return $chosen;
        };

        try {
            return Cache::lock("flow-split:{$flowId}:{$node->id}", 10)->block(5, $deal);
        } catch (LockTimeoutException) {
            // A customer is waiting on this answer. A count that is off by one
            // costs the test a little precision; a flow that stops costs a lead.
            Log::warning('SplitTests: lock timed out, dealing unserialised', [
                'flow_id' => $flowId,
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
            ]);

            return $deal();
        }
    }

    /**
     * How every split test in this flow is doing, variant by variant.
     *
     * Three measures, each a step further down the funnel:
     *   conversations — dealt to the variant since the test (re)started;
     *   replied       — of those, how many wrote again after entering it,
     *                   which is the funnel holding their attention;
     *   buyers/sales  — conversations with a confirmed sale (a payment the
     *                   gateway settled, a receipt the AI approved) after
     *                   entering it, from the sales ledger.
     *
     * A sale counts for the conversation it happened in. One made in a later
     * conversation of the same customer is not attributed — the test is over
     * conversations, the unit the author set limits in.
     *
     * Sales are in the workspace's currency only: the ledger can in principle
     * hold another, and a sum across currencies is not a number.
     *
     * @return array{currency: string, sales_visible: bool, tests: list<array<string, mixed>>}
     */
    public static function results(Flow $flow, bool $withSales): array
    {
        $currency = $flow->tenant?->currency() ?? 'BRL';
        $tests = [];

        $nodes = FlowNode::where('flow_id', $flow->id)
            ->where('type', NodeType::SplitTest)
            ->orderBy('id')
            ->get();

        foreach ($nodes as $node) {
            $data = (array) ($node->data ?? []);
            $variants = SplitTestNodes::variants($data);
            $ids = array_column($variants, 'id');
            $since = SplitTestNodes::startedAt($data);

            $conversations = self::window($flow->id, $ids, $since)
                ->selectRaw('variant_id, count(*) as total')
                ->groupBy('variant_id')
                ->pluck('total', 'variant_id');

            $replied = self::window($flow->id, $ids, $since)
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('messages')
                        ->whereColumn('messages.conversation_id', 'flow_split_assignments.conversation_id')
                        ->whereColumn('messages.id', '>', 'flow_split_assignments.last_message_id')
                        ->where('messages.sender_type', SenderType::Incoming->value)
                        ->where('messages.message_type', '!=', MessageType::Info->value);
                })
                ->selectRaw('variant_id, count(*) as total')
                ->groupBy('variant_id')
                ->pluck('total', 'variant_id');

            $sales = $withSales
                ? self::window($flow->id, $ids, $since)
                    ->join('sales', function ($join) use ($currency) {
                        $join->on('sales.conversation_id', '=', 'flow_split_assignments.conversation_id')
                            ->on('sales.sold_at', '>=', 'flow_split_assignments.created_at')
                            ->where('sales.currency', '=', $currency);
                    })
                    ->selectRaw('flow_split_assignments.variant_id as variant_id')
                    ->selectRaw('count(distinct flow_split_assignments.conversation_id) as buyers')
                    ->selectRaw('count(sales.id) as sales_count')
                    ->selectRaw('coalesce(sum(sales.amount_cents), 0) as revenue')
                    ->groupBy('flow_split_assignments.variant_id')
                    ->get()
                    ->keyBy('variant_id')
                : collect();

            $tests[] = [
                'node_id' => $node->id,
                'mode' => SplitTestNodes::mode($data),
                'started_at' => $since?->toIso8601String(),
                'variants' => array_map(function (array $variant) use ($conversations, $replied, $sales, $withSales) {
                    $sale = $sales->get($variant['id']);

                    return [
                        'id' => $variant['id'],
                        'label' => $variant['label'],
                        'conversations' => (int) ($conversations[$variant['id']] ?? 0),
                        'replied' => (int) ($replied[$variant['id']] ?? 0),
                        'buyers' => $withSales ? (int) ($sale->buyers ?? 0) : null,
                        'sales' => $withSales ? (int) ($sale->sales_count ?? 0) : null,
                        'revenue_cents' => $withSales ? (int) ($sale->revenue ?? 0) : null,
                    ];
                }, $variants),
            ];
        }

        return [
            'currency' => $currency,
            'sales_visible' => $withSales,
            'tests' => $tests,
        ];
    }

    /**
     * This test's rows: these variants of this flow, from when it (re)started.
     *
     * @param  list<string>  $variantIds
     */
    private static function window(int $flowId, array $variantIds, ?CarbonImmutable $since): Builder
    {
        return FlowSplitAssignment::query()
            ->where('flow_split_assignments.flow_id', $flowId)
            ->whereIn('flow_split_assignments.variant_id', $variantIds)
            ->when($since, fn (Builder $query) => $query->where('flow_split_assignments.created_at', '>=', $since));
    }
}
