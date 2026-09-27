<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;

/**
 * One thing an MCP client can do.
 *
 * Three gates, checked in this order and all three required:
 *
 *   1. the plan feature the surface lives behind (`feature()`)
 *   2. the OAuth scope the person granted this editor (`scope()`)
 *   3. the person's own permission, read live (`permission()`)
 *
 * The third is the one that matters most and the one that is easiest to leave
 * out. A scope is a decision the person made once, about an editor; a
 * permission is a decision the workspace makes continuously, about them. Take
 * the flow role away in the dashboard and the next tool call fails, with
 * nobody having touched the connection.
 */
abstract class Tool
{
    abstract public function name(): string;

    /** A short human name for a client's tool list. */
    abstract public function title(): string;

    /**
     * What the model reads to decide whether to call this.
     *
     * Written for a model, not for a changelog: say what it does, what it needs
     * and what it costs. Where a tool is part of a sequence, say which one
     * comes first — a model that reads "call get_flow first" does.
     */
    abstract public function description(): string;

    /** JSON Schema 2020-12. */
    abstract public function inputSchema(): array;

    abstract public function scope(): string;

    /** The Spatie permission the acting person must hold. */
    abstract public function permission(): string;

    abstract public function run(array $arguments, McpConnection $connection, User $user): ToolResult;

    public function feature(): Feature
    {
        return Feature::Flow;
    }

    /**
     * Behaviour hints. Clients use these to decide what to confirm with the
     * person before calling, so an honest `destructiveHint` is a safety
     * feature, not metadata.
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    public function outputSchema(): ?array
    {
        return null;
    }

    /** The definition as `tools/list` publishes it. */
    final public function definition(): array
    {
        return array_filter([
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'outputSchema' => $this->outputSchema(),
            'annotations' => $this->annotations(),
        ], fn ($value) => $value !== null);
    }

    /** A schema for a tool that takes nothing, in the form the spec recommends. */
    protected function noArguments(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }
}
