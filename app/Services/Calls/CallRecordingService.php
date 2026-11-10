<?php

namespace App\Services\Calls;

use App\Enums\Billing\Feature;
use App\Events\MessageUpdated;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UserFacingException;
use App\Jobs\FinalizeCallRecording;
use App\Jobs\TranscribeCallRecording;
use App\Models\Call;
use App\Models\Message;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiTranscription;
use App\Services\Billing\SubscriptionGate;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaRetention;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A recorded WhatsApp call: captured, joined, attached, read.
 *
 * The audio only exists in the answering browser, so that is where it is
 * recorded — both voices mixed — and it is uploaded in pieces every few
 * seconds while the call runs, so a closed tab costs the last few seconds and
 * not the whole call. When the call is over the pieces are joined (and
 * re-encoded to AAC, which every browser plays and every transcription model
 * accepts), the file is attached to the call's note in the thread, and the AI
 * agent of the connection transcribes and summarises it.
 *
 * Attached to the note rather than kept beside it on purpose: the note is the
 * thread's record of the call, and an attachment there falls under everything
 * media already has — signed links that expire with the file, `media:purge`
 * after the retention window (90 days), the "expired" state the bubble draws.
 * The transcript and summary are text in the note's meta and outlive the audio.
 */
class CallRecordingService
{
    /** Where the pieces wait until the call is over (private disk). */
    private const PARTS_DIR = 'call-recordings';

    /** The containers a browser MediaRecorder produces, by declared type. */
    private const CONTAINERS = [
        'audio/webm' => 'webm',
        'audio/mp4' => 'mp4',
        'audio/ogg' => 'ogg',
    ];

    /** Pieces may trail the end of the call by this long (a slow upload). */
    private const LATE_PIECE_SECONDS = 180;

    public function __construct(private AiAgentHubTenantService $hub) {}

    /** Whether this connection records its calls. */
    public static function enabledFor(Call $call): bool
    {
        return (bool) $call->connection?->call_recording;
    }

    /** The answering browser's piece number `$index` of the recording. */
    public function storePiece(Call $call, User $user, UploadedFile $file, int $index, string $mime): void
    {
        $call->refresh();

        if ((int) $call->answered_by_user_id !== (int) $user->id) {
            throw new UserFacingException('Only the agent on this call can record it.', 403, 'call_not_yours');
        }

        $late = $call->ended_at !== null && $call->ended_at->lt(now()->subSeconds(self::LATE_PIECE_SECONDS));

        if (! in_array($call->recording_status, [Call::RECORDING, Call::RECORDING_PROCESSING], true) || $late) {
            throw new UserFacingException('This call is not being recorded.', 409, 'call_not_recording');
        }

        $container = self::CONTAINERS[strtolower(trim(explode(';', $mime)[0]))] ?? null;

        if ($container === null) {
            throw new UserFacingException('Unsupported recording format.', 422, 'recording_format_unsupported');
        }

        MediaStorage::disk()->putFileAs(
            self::PARTS_DIR.'/'.$call->id,
            $file,
            sprintf('part-%05d.%s', $index, $container),
        );

        Call::query()->whereKey($call->id)->update([
            'recording_mime' => $container,
            'recording_parts' => max((int) $call->recording_parts, $index + 1),
        ]);
    }

    /** The browser says it sent everything: join it now rather than later. */
    public function complete(Call $call, User $user): void
    {
        if ((int) $call->answered_by_user_id !== (int) $user->id || $call->recording_status !== Call::RECORDING) {
            return;
        }

        $call->forceFill(['recording_status' => Call::RECORDING_PROCESSING])->save();

        FinalizeCallRecording::dispatch($call->id)->onQueue(config('queue.media'));
    }

    /**
     * The call ended while recording. The browser normally says "complete"
     * itself a second later; this is the guarantee for the one that closed
     * before it could.
     */
    public function scheduleFallback(Call $call): void
    {
        if ($call->recording_status !== Call::RECORDING) {
            return;
        }

        FinalizeCallRecording::dispatch($call->id)
            ->onQueue(config('queue.media'))
            ->delay(now()->addSeconds(60));
    }

    /** Join the pieces, attach the file to the note, hand it to the AI. */
    public function finalize(Call $call): void
    {
        $lock = Cache::lock('call-recording:'.$call->id, 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $call->refresh();

            if (! in_array($call->recording_status, [Call::RECORDING, Call::RECORDING_PROCESSING], true)) {
                return;
            }

            $disk = MediaStorage::disk();
            $parts = collect($disk->files(self::PARTS_DIR.'/'.$call->id))->sort()->values();

            if ($parts->isEmpty()) {
                $call->forceFill(['recording_status' => Call::RECORDING_EMPTY])->save();
                $this->writeRecordingMeta($call, ['status' => Call::RECORDING_EMPTY]);

                return;
            }

            $container = $call->recording_mime ?: pathinfo((string) $parts->first(), PATHINFO_EXTENSION);
            $raw = tempnam(sys_get_temp_dir(), 'callrec_').'.'.$container;
            $out = fopen($raw, 'wb');

            foreach ($parts as $part) {
                $stream = $disk->readStream($part);
                stream_copy_to_stream($stream, $out);
                fclose($stream);
            }

            fclose($out);

            [$file, $extension] = $this->toAac($raw) ?? [$raw, $container === 'mp4' ? 'm4a' : $container];

            $note = $call->message_id ? Message::query()->with('conversation')->find($call->message_id) : null;

            if (! $note) {
                @unlink($raw);
                @unlink($file);
                $call->forceFill(['recording_status' => Call::RECORDING_FAILED])->save();

                return;
            }

            $path = MediaFilename::path(
                'media',
                'calls/'.$call->id,
                'Ligação '.($call->answered_at ?? $call->created_at)->format('Y-m-d H-i'),
                $extension,
            );

            $handle = fopen($file, 'rb');
            $disk->put($path, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($raw);
            if ($file !== $raw) {
                @unlink($file);
            }

            $disk->deleteDirectory(self::PARTS_DIR.'/'.$call->id);

            $transcribe = $this->agentFor($call) !== null;
            $status = $transcribe ? Call::RECORDING_TRANSCRIBING : Call::RECORDING_READY;

            $note->forceFill(['attachment' => $path])->save();
            $call->forceFill(['recording_status' => $status])->save();
            $this->writeRecordingMeta($call, array_filter([
                'status' => $status,
                'duration_seconds' => $call->duration_seconds,
                'reason' => $transcribe ? null : 'no_ai_agent',
            ], fn ($value) => $value !== null));

            if ($transcribe) {
                TranscribeCallRecording::dispatch($call->id);
            }

            Log::info('Call: recording attached', ['call_id' => $call->id, 'path' => $path, 'parts' => $parts->count()]);
        } catch (Throwable $e) {
            Log::error('Call: recording could not be finalised', ['call_id' => $call->id, 'error' => $e->getMessage()]);
            $call->forceFill(['recording_status' => Call::RECORDING_FAILED])->save();
            $this->writeRecordingMeta($call, ['status' => Call::RECORDING_FAILED]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Transcript + summary, in one run of the connection's AI agent: the hub
     * transcribes the attachment (inputAudio) to feed its own model, and the
     * model's reply to the instruction is the summary.
     */
    public function transcribe(Call $call): void
    {
        $call->refresh();
        $note = $call->message_id ? Message::query()->with('conversation.connection', 'conversation.contact')->find($call->message_id) : null;
        $agent = $this->agentFor($call);

        if (! $note || ! $note->attachment || ! $agent) {
            $this->settle($call, ['reason' => 'no_ai_agent']);

            return;
        }

        $maxBytes = (int) config('calls.recording.max_ai_bytes', 24 * 1024 * 1024);

        if ($note->attachment_size && $note->attachment_size > $maxBytes) {
            $this->settle($call, ['reason' => 'too_long']);

            return;
        }

        $url = MediaRetention::signedUrl($note->attachment, $note, $note->conversation);

        if (! $url) {
            $this->settle($call, ['reason' => 'failed']);

            return;
        }

        $extension = strtolower(pathinfo($note->attachment, PATHINFO_EXTENSION));
        $attachments = [[
            'type' => 'audio',
            'url' => $url,
            'name' => basename($note->attachment),
            'mimeType' => match ($extension) {
                'm4a', 'mp4' => 'audio/mp4',
                'webm' => 'audio/webm',
                'ogg' => 'audio/ogg',
                default => null,
            },
        ]];
        $attachments = [array_filter($attachments[0], fn ($value) => $value !== null)];

        $tenant = $call->tenant;
        $inputAudio = AiTranscription::options(null, $attachments, $tenant);

        // Without the transcription block the hub hands the model a file it
        // never hears (AI_AUDIO_INPUT_ENABLED off), and the "summary" would be
        // made up. Better to say there is none.
        if ($inputAudio === []) {
            $this->settle($call, ['reason' => 'failed']);

            return;
        }

        try {
            $run = $this->hub->runAgent(
                $agent,
                $note->conversation,
                self::INSTRUCTION,
                metadata: ['purpose' => 'call_summary', 'call_id' => $call->id],
                // Its own hub conversation: the call's words must not become
                // part of what the agent remembers of the chat, nor the chat's
                // history part of the summary.
                conversationExternalId: 'call-summary:'.$call->id,
                attachments: $attachments,
                inputAudio: $inputAudio,
                // A summary of a call the model never heard would be invented.
                retryWithoutExtras: false,
            );
        } catch (CreditExhaustedException) {
            $this->settle($call, ['reason' => 'no_credit']);

            return;
        } catch (Throwable $e) {
            Log::warning('Call: transcription failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
            $this->settle($call, ['reason' => 'failed']);

            return;
        }

        $transcript = trim((string) data_get($run->metadata, 'inputAudio.items.0.text', ''));
        $summary = trim((string) $run->output_message);

        $this->settle($call, array_filter([
            'transcript' => $transcript !== '' ? $transcript : null,
            'summary' => $summary !== '' ? $summary : null,
            'reason' => $transcript === '' && $summary === '' ? 'failed' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Written in English for the model; the summary comes back in the language
     * of the call. Phrased without "agent" or "human": the hub scans this text
     * for handoff requests.
     */
    private const INSTRUCTION = 'This is the recording of a WhatsApp phone call between a company and its customer. '
        .'Write a short summary of the call in the same language that is spoken in it: what the customer wanted, '
        .'what was explained, agreed or promised, and any next step. Plain text, at most six short lines. '
        .'Do not greet anyone and do not address the customer.';

    /** @param  array<string, mixed>  $fields */
    private function settle(Call $call, array $fields): void
    {
        $call->forceFill(['recording_status' => Call::RECORDING_READY])->save();
        $this->writeRecordingMeta($call, array_merge(['status' => Call::RECORDING_READY], $fields));
    }

    /** The AI agent that reads this connection's calls, when the plan has AI. */
    private function agentFor(Call $call): ?\App\Models\AiHubAgent
    {
        $connection = $call->connection()->with('aiSuggestAgent.aiHubTenant')->first();
        $agent = $connection?->aiSuggestAgent;

        if (! $agent || ! $call->tenant) {
            return null;
        }

        if (config('services.billing.enforce') && ! app(SubscriptionGate::class)->feature($call->tenant, Feature::AiAgentHub->value)) {
            return null;
        }

        return $agent;
    }

    /**
     * The recording's state on the call's note, which is what the thread
     * renders. Merged, so the transcript written later does not lose the
     * duration written first.
     *
     * @param  array<string, mixed>  $fields
     */
    private function writeRecordingMeta(Call $call, array $fields): void
    {
        $note = $call->message_id ? Message::query()->find($call->message_id) : null;

        if (! $note) {
            return;
        }

        $meta = $note->meta ?? [];
        $current = is_array($meta['call']['recording'] ?? null) ? $meta['call']['recording'] : [];

        // A later state replaces the reason an earlier one gave.
        if (isset($fields['status'])) {
            unset($current['reason']);
        }

        $meta['call']['recording'] = array_merge($current, $fields);
        $note->forceFill(['meta' => $meta])->save();

        try {
            broadcast(new MessageUpdated($note->refresh()));
        } catch (Throwable $e) {
            Log::warning('Call: recording broadcast failed', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Re-encode to AAC in an .m4a: playable in every browser (Safari included,
     * which the WebM Chrome records is not reliably), accepted by every
     * transcription model, and with a real duration in its header — WebM from
     * MediaRecorder has none, so players show "--:--". Null when ffmpeg is
     * missing or fails; the raw recording is attached instead.
     *
     * @return array{0: string, 1: string}|null
     */
    private function toAac(string $source): ?array
    {
        $binary = config('media.ffmpeg_path') ?: 'ffmpeg';
        $output = tempnam(sys_get_temp_dir(), 'callrec_out_').'.m4a';

        $command = sprintf(
            '%s -hide_banner -loglevel error -y -i %s -vn -ac 1 -c:a aac -b:a 48k -movflags +faststart %s 2>&1',
            escapeshellarg((string) $binary),
            escapeshellarg($source),
            escapeshellarg($output),
        );

        exec($command, $lines, $status);

        if ($status !== 0 || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);
            Log::warning('Call: recording kept as recorded, ffmpeg could not convert it', [
                'status' => $status,
                'ffmpeg' => implode(' | ', array_slice($lines ?? [], -3)),
            ]);

            return null;
        }

        return [$output, 'm4a'];
    }
}
