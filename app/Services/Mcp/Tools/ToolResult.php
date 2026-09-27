<?php

namespace App\Services\Mcp\Tools;

/**
 * What a tool hands back.
 *
 * Two fields because MCP carries two audiences in one result: `text` is what
 * the model reads, `structured` is what a program can act on. A tool that has
 * structured data returns both — the specification asks for the JSON to also
 * appear as text, and a model that can read the answer in prose does not have
 * to parse anything to use it.
 */
final class ToolResult
{
    private function __construct(
        public readonly string $text,
        public readonly ?array $structured = null,
    ) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * A structured payload, serialised into the text block as well.
     *
     * `$summary` leads the text when given: "3 flows" reads better at the top
     * of a wall of JSON than the JSON does, and it is what the model quotes
     * back to the person.
     */
    public static function data(array $structured, ?string $summary = null): self
    {
        $json = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self(
            $summary !== null ? $summary."\n\n".$json : (string) $json,
            $structured,
        );
    }

    /**
     * Text the model reads, plus separate structured data.
     *
     * For the case `data()` does not fit: where the prose is the point and the
     * JSON is a lookup beside it, rather than a serialisation of the same
     * thing. The flow specification is the example — 17 KB of format
     * documentation, with the workspace's ids attached.
     */
    public static function dataWithText(string $text, array $structured): self
    {
        return new self($text, $structured);
    }
}
