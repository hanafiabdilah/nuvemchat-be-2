<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Mcp\Tools\CreateFlowTool;
use App\Services\Mcp\Tools\DeleteFlowTool;
use App\Services\Mcp\Tools\FlowSpecificationTool;
use App\Services\Mcp\Tools\GetFlowTool;
use App\Services\Mcp\Tools\ListFlowsTool;
use App\Services\Mcp\Tools\Tool;
use App\Services\Mcp\Tools\UpdateFlowTool;
use App\Services\Mcp\Tools\ValidateFlowTool;

/**
 * Every tool this server offers, and who may see each one.
 *
 * ⚠️ `tools/list` is filtered, not merely enforced. A model shown a tool it
 * cannot use will call it — that is what a tool list is for — and then spend a
 * turn on an error it can do nothing about. Hiding what the caller cannot reach
 * is cheaper for everyone, and the specification says plainly that the set may
 * vary by the authorization presented.
 *
 * Order is fixed and meaningful: read before write, and the specification tool
 * ahead of anything that writes, because that is the order a model should work
 * in and the list is part of the prompt.
 */
final class ToolRegistry
{
    /** @var list<class-string<Tool>> */
    private const TOOLS = [
        ListFlowsTool::class,
        GetFlowTool::class,
        FlowSpecificationTool::class,
        ValidateFlowTool::class,
        CreateFlowTool::class,
        UpdateFlowTool::class,
        DeleteFlowTool::class,
    ];

    public function __construct(
        private SubscriptionGate $gate,
    ) {}

    /** @return list<Tool> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::TOOLS);
    }

    public function find(string $name): ?Tool
    {
        foreach ($this->all() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @return list<Tool> */
    public function availableTo(McpConnection $connection, User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Tool $tool) => $this->permits($tool, $connection, $user),
        ));
    }

    /**
     * The three gates, in the order that costs least: the plan is a cached
     * lookup, the scope is an array, the permission hits Spatie's cache.
     */
    public function permits(Tool $tool, McpConnection $connection, User $user): bool
    {
        if (config('services.billing.enforce')
            && ! $this->gate->feature($connection->tenant, $tool->feature()->value)) {
            return false;
        }

        if (! Scopes::satisfies($connection->scopeList(), $tool->scope())) {
            return false;
        }

        return $user->can($tool->permission());
    }

    /**
     * Why a specific tool is unavailable, for the error a caller gets when it
     * names one anyway — a model that cached an older list, or a person who
     * typed it. "You have not granted this editor permission to change flows"
     * is actionable; "unknown tool" sends them looking in the wrong place.
     */
    public function refusalFor(Tool $tool, McpConnection $connection, User $user): string
    {
        if (config('services.billing.enforce')
            && ! $this->gate->feature($connection->tenant, $tool->feature()->value)) {
            return "This workspace's plan does not include the feature behind {$tool->name()}.";
        }

        if (! Scopes::satisfies($connection->scopeList(), $tool->scope())) {
            return "This connection was not granted the {$tool->scope()} scope. "
                .'Disconnect it in Developer › MCP and connect again, allowing that access.';
        }

        return "Your account does not have the {$tool->permission()} permission in this workspace.";
    }
}
