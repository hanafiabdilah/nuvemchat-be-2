<?php

namespace App\Services\Mcp;

use App\Enums\Billing\Feature;

/**
 * The scope vocabulary of the MCP server.
 *
 * ⚠️ A scope is a ceiling, never a grant. It says what the person *allowed this
 * editor* to reach; what they may actually do is their own Spatie permissions,
 * re-read on every tool call. The two are checked one after the other and both
 * must pass.
 *
 * That ordering is the reason the consent screen offers a scope the person
 * cannot currently exercise: permissions move — someone is given the flow role
 * next week — and a grant narrowed to today's roles would have to be re-approved
 * for every change. The ceiling is a decision about the editor; the floor is a
 * decision about the person, and it is made fresh each time.
 *
 * Phase one is flows. Adding a surface means a constant here, a line in
 * `all()`, and tools that name it — nothing in the OAuth layer changes.
 */
final class Scopes
{
    public const FLOWS_READ = 'mcp:flows.read';

    public const FLOWS_WRITE = 'mcp:flows.write';

    /**
     * Every scope this server issues, in the order a consent screen should
     * read them: what it can see before what it can change.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::FLOWS_READ, self::FLOWS_WRITE];
    }

    /**
     * What a client gets when it asks for nothing.
     *
     * Read-only. A client that does not say what it wants has not asked to
     * change anything, and the MCP specification's own advice is that
     * `scopes_supported` be the minimum a server needs to be useful.
     *
     * @return list<string>
     */
    public static function fallback(): array
    {
        return [self::FLOWS_READ];
    }

    /**
     * Parse a space-delimited `scope` parameter, dropping anything unknown.
     *
     * Unknown scopes are ignored rather than refused: clients send supersets —
     * a scope list cached from a newer build of this server, or a hopeful
     * guess — and failing the whole authorization over one unrecognised word
     * would break a flow the person can do nothing about. What they approve is
     * what comes back from here, and the consent screen shows exactly that.
     *
     * @return list<string>
     */
    public static function parse(?string $requested): array
    {
        $asked = preg_split('/\s+/', trim((string) $requested), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $known = array_values(array_intersect(self::all(), $asked));

        return $known !== [] ? $known : self::fallback();
    }

    /**
     * Writing implies reading. A client granted `flows.write` and not
     * `flows.read` could save a flow it is not allowed to fetch first — which
     * is not a narrower permission, it is a worse one: the only safe way to
     * edit a flow here is to read it, change it and send the whole thing back.
     *
     * @param  list<string>  $granted
     */
    public static function satisfies(array $granted, string $needed): bool
    {
        if (in_array($needed, $granted, true)) {
            return true;
        }

        return $needed === self::FLOWS_READ && in_array(self::FLOWS_WRITE, $granted, true);
    }

    /** The plan feature the surface behind a scope lives behind. */
    public static function feature(string $scope): Feature
    {
        return match ($scope) {
            self::FLOWS_READ, self::FLOWS_WRITE => Feature::Flow,
            default => Feature::Mcp,
        };
    }

    /** The `scope` string for a WWW-Authenticate challenge or a metadata document. */
    public static function toHeader(array $scopes): string
    {
        return implode(' ', $scopes);
    }
}
