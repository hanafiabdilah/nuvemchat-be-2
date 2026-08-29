<?php

namespace App\Services\AiAgentHub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The voices a flow author can pick from, straight from ElevenLabs.
 *
 * Two things make this possible without holding anybody's secret: their
 * `/v1/voices` answers unauthenticated with the shared library (`/v2` is the
 * one that needs a key and returns an account's own clones), and the voice ids
 * in that library are the same for every account — which is why pasting an id
 * from someone else's screenshot works at all.
 *
 * Fetched rather than hardcoded on purpose. ElevenLabs states that its current
 * Default voices expire on 31 December 2026 and are being replaced; a list
 * baked into this repo would keep offering them until every flow that picked
 * one started failing at once, and it would fail looking like our bug rather
 * than like their deadline.
 *
 * Cloned and private voices are not here and cannot be: they live behind the
 * account's key, which is at the hub. Those are still pasted by id.
 */
class ElevenLabsVoices
{
    private const ENDPOINT = 'https://api.elevenlabs.io/v1/voices';

    private const CACHE_KEY = 'ai:elevenlabs:voices';

    /**
     * The catalogue, normalised and cached.
     *
     * A failure returns an empty list rather than throwing: the dropdown is a
     * convenience over a field that still accepts a pasted id, and no flow
     * should stop being editable because a third party is having a bad day.
     *
     * @return array<int, array{id: string, name: string, category: string, description: ?string}>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(12), function (): array {
            try {
                $response = Http::timeout(15)->get(self::ENDPOINT);
            } catch (\Throwable $th) {
                Log::warning('ElevenLabsVoices: the voice list could not be fetched', [
                    'error' => $th->getMessage(),
                ]);

                return [];
            }

            if (! $response->successful()) {
                Log::warning('ElevenLabsVoices: the voice list was refused', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            $voices = collect($response->json('voices') ?? [])
                ->filter(fn ($voice) => is_array($voice) && ! empty($voice['voice_id']) && ! empty($voice['name']))
                ->map(fn (array $voice) => [
                    'id' => (string) $voice['voice_id'],
                    'name' => (string) $voice['name'],
                    'category' => (string) ($voice['category'] ?? 'premade'),
                    'description' => self::describe($voice),
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            Log::info('ElevenLabsVoices: voice list refreshed', ['count' => count($voices)]);

            return $voices;
        });
    }

    /** Drop the cached copy — for the moment somebody needs it re-read now. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A one-line "what does this voice sound like", from the labels ElevenLabs
     * attaches. Gender and accent are what a person actually chooses on; the
     * rest of the payload (fine-tuning state, samples, sharing) is noise for a
     * dropdown and never leaves this method.
     */
    private static function describe(array $voice): ?string
    {
        $labels = is_array($voice['labels'] ?? null) ? $voice['labels'] : [];

        $parts = array_filter([
            $labels['gender'] ?? null,
            $labels['accent'] ?? null,
            $labels['use_case'] ?? $labels['description'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        return $parts !== [] ? implode(' · ', $parts) : null;
    }
}
