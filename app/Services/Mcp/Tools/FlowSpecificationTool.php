<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowVocabulary;
use App\Services\Mcp\Scopes;

/**
 * The flow format, and this workspace's real ids.
 *
 * ⚠️ The most important tool on this server, and the one a model will skip if
 * the description does not insist. Half of `FlowBlueprint`'s rules are
 * tenant-scoped `exists` checks — a tag, an agent, an integration — and there
 * is no way to guess one of those ids. A model that writes a flow without this
 * produces JSON that parses, passes nothing, and reads to the person as a
 * broken product.
 *
 * The specification itself is `FlowBlueprint::specification()`: ~17 KB of
 * Markdown generated from the same constants the validator enforces, and the
 * very text the in-app assistant is prompted with. Serving anything else here
 * would mean the model and the product disagreed about what a flow is.
 */
class FlowSpecificationTool extends Tool
{
    public function name(): string
    {
        return 'get_flow_specification';
    }

    public function title(): string
    {
        return 'Flow format and workspace vocabulary';
    }

    public function description(): string
    {
        return 'Call this once before writing or changing any flow. Returns the exact node format this '
            .'workspace accepts — every step type, its fields and its branches — together with the real '
            .'tags, agents, AI agents, integrations, lead stages and other flows you may reference. '
            .'Ids that are not in this list will be refused: never invent one.';
    }

    public function inputSchema(): array
    {
        return $this->noArguments();
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
        $vocabulary = FlowVocabulary::forTenant((int) $user->tenant_id);

        $text = FlowBlueprint::specification()
            ."\n\n# Ids available in this workspace\n\n"
            ."Use only these. Anything else is refused when the flow is saved.\n\n"
            ."```json\n"
            .json_encode($vocabulary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n```\n";

        // Both halves, and the text is not merely the JSON: the specification is
        // prose a model reads, while the vocabulary is a lookup a program walks.
        return ToolResult::dataWithText($text, ['vocabulary' => $vocabulary]);
    }
}
