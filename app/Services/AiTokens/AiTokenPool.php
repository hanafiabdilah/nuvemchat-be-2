<?php

namespace App\Services\AiTokens;

use App\Models\AiTokenPoolKey;
use Illuminate\Support\Collection;

/**
 * Which of the platform's keys a workspace gets.
 *
 * The choice is random, and it is made once — at the moment of renting, not on
 * every run. That is not a shortcut: the credential is a property of the agent
 * (`AiHubAgent.providerCredentialId`), so re-rolling per run would mean a
 * `PATCH /agents/{id}` in front of every reply, paying latency on the hot path
 * and racing any other run in flight on the same agent. Randomising the
 * assignment already spreads workspaces across keys, which is the load
 * behaviour the pool exists for; per-run randomness would buy a smoother
 * distribution for one busy workspace at a price the quiet ones pay too.
 *
 * A key is re-rolled when it stops being usable — revoked, or paused with the
 * workspace still on it — and that path lives in AiTokenRentalService.
 *
 * The randomness is weighted. A pool where an admin has added one key on a
 * high rate-limit tier and three on a low one is a pool where uniform choice
 * sends three quarters of new workspaces to the keys that can least take them.
 */
class AiTokenPool
{
    /**
     * Pick a key for a workspace about to rent, or null when the pool has
     * nothing left to give.
     *
     * Null is a real, expected outcome — every key full or paused — and the
     * caller turns it into a message an admin can act on, never a 500.
     *
     * `$excludeIds` is how a rotation avoids handing back the key it is moving
     * away from.
     *
     * @param  list<int>  $excludeIds
     */
    public function pick(string $provider, array $excludeIds = []): ?AiTokenPoolKey
    {
        $candidates = AiTokenPoolKey::query()
            ->availableFor($provider)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        return $this->weightedRandom($candidates);
    }

    /**
     * Providers the pool can currently serve.
     *
     * Read by the tenant-facing rental screen, so that a provider with no key
     * behind it is never offered — a workspace clicking "rent" and being told
     * there is nothing available has already been let down by the time it finds
     * out.
     *
     * @return list<string>
     */
    public function availableProviders(): array
    {
        return AiTokenPoolKey::query()
            ->distinct()
            ->pluck('provider')
            // One eligibility query per distinct provider, not per key: a pool
            // with twenty OpenAI keys would otherwise ask the same question
            // twenty times to produce one answer.
            ->filter(fn (string $provider) => $this->pick($provider) !== null)
            ->values()
            ->all();
    }

    /**
     * Weighted pick over a loaded collection.
     *
     * Done in PHP rather than with an `ORDER BY RAND()` variant because the
     * weighting is not expressible portably across MySQL and the SQLite the
     * tests use, and the pool is a handful of rows — the query to fetch them
     * costs more than the arithmetic.
     *
     * @param  Collection<int, AiTokenPoolKey>  $candidates
     */
    protected function weightedRandom(Collection $candidates): ?AiTokenPoolKey
    {
        $total = (int) $candidates->sum('weight');

        if ($total <= 0) {
            return null;
        }

        $roll = random_int(1, $total);

        foreach ($candidates as $candidate) {
            $roll -= max(1, (int) $candidate->weight);

            if ($roll <= 0) {
                return $candidate;
            }
        }

        // Unreachable while the weights sum to $total, but returning the last
        // candidate is the only answer that is never wrong.
        return $candidates->last();
    }
}
