<?php

namespace App\Services\AiAgentHub;

use App\Events\MessageUpdated;
use App\Models\AiHubRun;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Puts the transcription the hub produced back on the voice note it came from.
 *
 * The hub transcribes to feed its own model and returns the text as a
 * by-product; nothing forces us to keep it. Keeping it is the point: the run
 * costs the same either way, and without it a human who takes the thread over
 * is left with an audio player and no way to skim what was said — the one
 * thing an agent triaging twenty conversations most needs. It also turns
 * "[audio]" into a readable line for every later AI-suggest draft.
 */
class AiTranscripts
{
    /**
     * Store the run's transcriptions against the messages that carried the
     * audio, matched by position: the hub returns one item per audio
     * attachment, in the order they were sent.
     *
     * @param  array<int, array{attachment: array<string, mixed>, message: Message}>  $entries
     *         The attachment list as it was handed to the hub, sources included.
     */
    public static function store(AiHubRun $run, array $entries): void
    {
        $items = data_get($run->metadata, 'inputAudio.items');

        if (! is_array($items) || $items === []) {
            return;
        }

        $sources = array_values(array_map(
            fn (array $entry) => $entry['message'],
            array_filter($entries, fn (array $entry) => ($entry['attachment']['type'] ?? null) === 'audio'),
        ));

        if (count($items) !== count($sources)) {
            // Positional matching is the only pairing the hub's response
            // allows, so a length mismatch means the pairing cannot be trusted
            // past the overlap. Worth a line: a transcript written onto the
            // wrong voice note is worse than none at all.
            Log::warning('AiTranscripts: transcription count does not match the audio sent', [
                'ai_hub_run_id' => $run->id,
                'items' => count($items),
                'audio_attachments' => count($sources),
            ]);
        }

        foreach ($sources as $index => $message) {
            $text = trim((string) data_get($items[$index] ?? null, 'text', ''));

            if ($text === '') {
                continue;
            }

            self::apply($message, $text, $items[$index], $run);
        }
    }

    private static function apply(Message $message, string $text, array $item, AiHubRun $run): void
    {
        $message->update([
            'meta' => array_merge((array) ($message->meta ?? []), [
                'transcription' => array_filter([
                    'text' => $text,
                    'model' => $item['model'] ?? null,
                    'language' => $item['language'] ?? null,
                    'ai_hub_run_id' => $run->id,
                    'at' => now()->toIso8601String(),
                ], fn ($value) => $value !== null),
            ]),
        ]);

        // Unlike the byte count an observer writes, this is content: the
        // bumped `updated_at` is what carries it to clients on the next delta
        // sync, and the broadcast is what puts it under the player for the
        // agent watching the thread right now.
        broadcast(new MessageUpdated($message));
    }
}
