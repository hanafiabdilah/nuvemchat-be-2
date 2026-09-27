<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithFlows;

/**
 * Check a graph without writing it.
 *
 * Free, and it exists so a model can correct itself before it commits. The
 * alternative is a failed create, a partially understood error and a second
 * attempt in front of somebody who is watching — and half of what this catches
 * is the kind of mistake that *would not fail at all*: a branch value the
 * engine will never take, a node nothing reaches. Those save perfectly and then
 * do nothing, months later, to a customer.
 */
class ValidateFlowTool extends Tool
{
    use WorksWithFlows;

    public function name(): string
    {
        return 'validate_flow';
    }

    public function title(): string
    {
        return 'Check a flow';
    }

    public function description(): string
    {
        return 'Check a flow graph against this workspace\'s rules without saving anything. '
            .'Returns the problems as sentences, including the ones that would save successfully and then '
            .'never run — a branch value the engine cannot produce, or a step nothing leads to. '
            .'Costs nothing; use it before create_flow or update_flow whenever you are unsure.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nodes' => [
                    'type' => 'array',
                    'description' => 'The steps, in the same shape get_flow returns.',
                    'items' => ['type' => 'object'],
                ],
                'edges' => [
                    'type' => 'array',
                    'description' => 'The connections between steps, in the same shape get_flow returns.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['nodes'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::FLOWS_READ;
    }

    public function permission(): string
    {
        return 'flows.view';
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        // prepareGraph throws on anything malformed, which is itself the answer
        // — the exception carries the per-node sentences.
        $graph = $this->prepareGraph($arguments);

        $problems = FlowBlueprint::structureProblems($graph['nodes'], $graph['edges']);

        $dropped = count($graph['dropped_edges']);

        if ($dropped > 0) {
            $problems[] = "{$dropped} edge(s) leave an output that already has one and would not be saved.";
        }

        return ToolResult::data(
            ['valid' => $problems === [], 'problems' => array_values($problems)],
            $problems === []
                ? 'This flow is sound.'
                : count($problems).' problem(s) to fix before saving.',
        );
    }
}
