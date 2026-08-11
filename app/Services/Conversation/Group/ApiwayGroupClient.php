<?php

namespace App\Services\Conversation\Group;

use App\Models\Connection;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads group subjects from the API Way core.
 *
 * whatsmeow message events carry no group subject — only GroupInfo (a rename)
 * and JoinedGroup do, and both are one-shot: a group that already existed when
 * the instance was linked, and is never renamed, would keep the JID placeholder
 * forever. These endpoints are the pull side of that push-only story:
 *
 *   GET /v1/group/group-metadata?instanceId&groupId={jid@g.us}  → one group
 *   GET /v1/group/get-all-groups?instanceId                     → every group
 *
 * Both answer `{ success, data }`. `data` is whatsmeow's GroupInfo, whose JSON
 * casing has moved before (webhooks send PascalCase), so the subject and the
 * JID are read through tolerant extractors rather than one fixed key.
 */
class ApiwayGroupClient
{
    /** Keys seen (or plausible) for a group's subject across core versions. */
    private const NAME_KEYS = ['Name', 'name', 'Subject', 'subject', 'GroupName', 'groupName', 'title'];

    /** Same, for the group's JID. */
    private const JID_KEYS = ['JID', 'jid', 'GroupJID', 'groupJid', 'id', 'Id', 'ID', 'groupId', 'GroupId'];

    /**
     * One group's subject, or null when the core has no name for it.
     * Throws on transport/HTTP failure so the caller can back off instead of
     * mistaking an outage for "this group has no name".
     */
    public function name(Connection $connection, string $groupJid): ?string
    {
        $response = $this->request($connection, '/v1/group/group-metadata', [
            'instanceId' => $this->instanceId($connection),
            'groupId' => $groupJid,
        ]);

        return $this->extractName($response->json('data'));
    }

    /**
     * Every group this instance belongs to, as [jid => name].
     * One round trip backfills a whole connection — which is the only way to
     * fix groups whose naming event was missed before the webhook existed.
     */
    public function allGroups(Connection $connection): array
    {
        $response = $this->request($connection, '/v1/group/get-all-groups', [
            'instanceId' => $this->instanceId($connection),
        ]);

        $data = $response->json('data');

        // The list may arrive bare or wrapped ({groups: []}, {Groups: []}).
        if (is_array($data) && ! array_is_list($data)) {
            $data = $data['groups'] ?? $data['Groups'] ?? $data['data'] ?? [];
        }

        if (! is_array($data)) {
            return [];
        }

        $groups = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $jid = $this->firstString($entry, self::JID_KEYS);
            $name = $this->extractName($entry);

            if ($jid !== null && $name !== null) {
                $groups[$jid] = $name;
            }
        }

        return $groups;
    }

    private function request(Connection $connection, string $path, array $query)
    {
        $token = $connection->credentials['token'] ?? null;

        if (! $token) {
            throw new RuntimeException('API Way connection has no instance token');
        }

        $response = Http::timeout(20)
            ->withToken($token)
            ->get(ApiwayConfig::baseUrl() . $path, $query);

        if ($response->failed()) {
            throw new RuntimeException('API Way ' . $path . ' failed: HTTP ' . $response->status());
        }

        return $response;
    }

    private function instanceId(Connection $connection): string
    {
        $instanceId = $connection->credentials['instance_id'] ?? null;

        if (! $instanceId) {
            throw new RuntimeException('API Way connection has no linked instance');
        }

        return $instanceId;
    }

    /**
     * The subject out of a GroupInfo object. Handles the nested shape too
     * (`GroupName: { Name: ... }`), which is how whatsmeow marshals it when the
     * embedded struct is not promoted.
     */
    private function extractName(mixed $data): ?string
    {
        if (is_string($data)) {
            return trim($data) !== '' ? trim($data) : null;
        }

        if (! is_array($data)) {
            return null;
        }

        foreach (['GroupName', 'groupName'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $nested = $this->firstString($data[$key], self::NAME_KEYS);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return $this->firstString($data, self::NAME_KEYS);
    }

    /** First key holding a non-empty string. */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
