<?php

namespace App\Services\Media;

use App\Exceptions\VideoCompressionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Shrinks a video until it fits a channel's size limit.
 *
 * The composer takes videos far bigger than any channel accepts — 200 MB
 * against WhatsApp Official's 16 MB — so the file is re-encoded on its way out,
 * aimed at the one number that matters: Channel::videoMaxBytes(). Everything
 * here follows from aiming at a size rather than at a quality:
 *
 *  - The bitrate is computed from the duration (bits available ÷ seconds), not
 *    picked from a ladder. A 30-second clip keeps a sharp picture; a 9-minute
 *    one gets a smaller one, because that is all 16 MB can hold.
 *  - The resolution follows the bitrate: 720p, 480p or 360p on the short side,
 *    never upscaled. Pushing 720p through 300 kbps looks worse than 480p does.
 *  - A video too long for a watchable picture is refused with the longest
 *    duration that would fit, instead of sent as a smear.
 *  - The rate is capped (`-maxrate` = `-b:v`), which is what makes the output
 *    size predictable enough to promise. One retry at a lower rate covers the
 *    rare file that still lands over.
 *
 * The output is always MP4 / H.264 / AAC / yuv420p with the index at the front
 * — the one combination every channel here plays, and the only one the Cloud
 * API accepts.
 */
class VideoCompressor
{
    /**
     * Share of the limit the encode aims for. The rest absorbs the MP4
     * container and the encoder drifting over its average on a busy scene.
     */
    private const SIZE_MARGIN = 0.9;

    private const AUDIO_KBPS = 96;

    /** Speech survives 48 kbps; a starved picture does not get better with 48 more. */
    private const LEAN_AUDIO_KBPS = 48;

    private const LEAN_AUDIO_BELOW_KBPS = 500;

    /**
     * Below this a picture is no longer worth sending. The video is refused as
     * too long, with the duration that would fit.
     */
    public const MIN_VIDEO_KBPS = 150;

    /**
     * No point spending more. 720p looks good here, and a smaller file reaches
     * the customer sooner — API Way's 50 MB limit would otherwise invite 6 Mbps
     * for a one-minute clip nobody watches on a laptop.
     */
    private const MAX_VIDEO_KBPS = 2500;

    /** The second attempt, for a file the first one could not get under the limit. */
    private const RETRY_FACTOR = 0.8;

    private ?bool $available = null;

    /** Whether ffmpeg and ffprobe can both be run. */
    public function available(): bool
    {
        return $this->available ??= self::runnable($this->ffmpeg()) && self::runnable($this->ffprobe());
    }

    /** Whether a file of this size has to be compressed before the channel takes it. */
    public static function needsCompression(int $bytes, ?int $limitBytes): bool
    {
        return $limitBytes !== null && $bytes > $limitBytes;
    }

    /**
     * The encode for a video of this duration under this limit.
     *
     * @return array{video_kbps: int, audio_kbps: int, short_side: int}
     *
     * @throws VideoCompressionException when no watchable bitrate fits
     */
    public static function plan(float $durationSeconds, bool $hasAudio, int $maxBytes): array
    {
        $budgetKbits = $maxBytes * 8 / 1000 * self::SIZE_MARGIN;
        $totalKbps = (int) floor($budgetKbits / max($durationSeconds, 1));

        $audio = $hasAudio ? self::AUDIO_KBPS : 0;

        if ($hasAudio && $totalKbps - $audio < self::LEAN_AUDIO_BELOW_KBPS) {
            $audio = self::LEAN_AUDIO_KBPS;
        }

        $video = $totalKbps - $audio;

        if ($video < self::MIN_VIDEO_KBPS) {
            $leanest = self::MIN_VIDEO_KBPS + ($hasAudio ? self::LEAN_AUDIO_KBPS : 0);

            throw VideoCompressionException::tooLong((int) floor($budgetKbits / $leanest));
        }

        $video = min($video, self::MAX_VIDEO_KBPS);

        return [
            'video_kbps' => $video,
            'audio_kbps' => $audio,
            'short_side' => match (true) {
                $video >= 1000 => 720,
                $video >= 500 => 480,
                default => 360,
            },
        ];
    }

    /**
     * Re-encode `$path` to fit `$maxBytes` and hand it back as an upload.
     *
     * ⚠️ The caller owns the returned file: it is a temp file this method
     * made, not one PHP will clean up — the job that sends it unlinks it.
     *
     * @throws VideoCompressionException
     */
    public function fit(string $path, int $maxBytes, string $name): UploadedFile
    {
        if (! $this->available()) {
            Log::error('VideoCompressor: ffmpeg or ffprobe is not installed, so a video cannot be compressed', [
                'ffmpeg' => $this->ffmpeg(),
                'ffprobe' => $this->ffprobe(),
            ]);

            throw VideoCompressionException::unavailable();
        }

        $probe = $this->probe($path);

        if ($probe === null) {
            throw VideoCompressionException::unreadable();
        }

        $plan = self::plan($probe['duration'], $probe['has_audio'], $maxBytes);
        $output = sys_get_temp_dir().'/'.uniqid('video_fit_', true).'.mp4';

        try {
            foreach ([1.0, self::RETRY_FACTOR] as $attempt => $factor) {
                $videoKbps = (int) floor($plan['video_kbps'] * $factor);

                if ($videoKbps < self::MIN_VIDEO_KBPS) {
                    break;
                }

                if (! $this->encode($path, $output, ['video_kbps' => $videoKbps] + $plan, $probe['has_audio'])) {
                    break;
                }

                clearstatcache(true, $output);
                $size = (int) filesize($output);

                Log::info('VideoCompressor: encoded', [
                    'attempt' => $attempt + 1,
                    'duration' => round($probe['duration'], 1),
                    'source_bytes' => (int) @filesize($path),
                    'bytes' => $size,
                    'limit_bytes' => $maxBytes,
                    'video_kbps' => $videoKbps,
                    'audio_kbps' => $plan['audio_kbps'],
                    'short_side' => $plan['short_side'],
                ]);

                if ($size > 0 && $size <= $maxBytes) {
                    $base = pathinfo($name, PATHINFO_FILENAME) ?: 'video';

                    return new UploadedFile($output, $base.'.mp4', 'video/mp4', null, true);
                }
            }
        } catch (\Throwable $e) {
            @unlink($output);

            throw $e;
        }

        @unlink($output);

        throw VideoCompressionException::failed();
    }

    /**
     * Duration, and whether there is sound — what the bitrate is computed from.
     * Null when the file has no readable duration or no picture.
     *
     * @return array{duration: float, has_audio: bool}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(60)->run([
            $this->ffprobe(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        if (! $result->successful()) {
            Log::warning('VideoCompressor: ffprobe could not read the video', [
                'ffprobe' => mb_substr(trim($result->errorOutput()), -500),
            ]);

            return null;
        }

        $json = json_decode($result->output(), true);
        $streams = is_array($json['streams'] ?? null) ? $json['streams'] : [];

        // Cover art in an MP4 is a "video" stream too; it is not the picture.
        $video = collect($streams)->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'video'
            && (int) ($stream['disposition']['attached_pic'] ?? 0) === 0);

        $duration = (float) ($json['format']['duration'] ?? $video['duration'] ?? 0);

        if ($video === null || $duration <= 0) {
            return null;
        }

        return [
            'duration' => $duration,
            'has_audio' => collect($streams)->contains(fn ($stream) => ($stream['codec_type'] ?? null) === 'audio'),
        ];
    }

    /**
     * @param  array{video_kbps: int, audio_kbps: int, short_side: int}  $plan
     */
    private function encode(string $source, string $output, array $plan, bool $hasAudio): bool
    {
        $side = $plan['short_side'];
        $video = $plan['video_kbps'];

        // Limit the short side, whichever way round the picture is — after
        // ffmpeg has applied the phone's rotation flag, so a portrait clip
        // stored as landscape is measured the way it is watched. Even numbers,
        // because yuv420p cannot be odd.
        $scale = "scale='if(gt(iw,ih),-2,trunc(min(iw,{$side})/2)*2)':'if(gt(iw,ih),trunc(min(ih,{$side})/2)*2,-2)'";

        $args = [
            ...$this->nice(),
            $this->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source,
            '-map', '0:v:0',
            ...($hasAudio ? ['-map', '0:a:0'] : []),
            '-vf', $scale.',format=yuv420p',
            // Phones record at 60 fps; at these bitrates the frames are better
            // spent on detail than on motion nobody notices in a chat.
            '-fpsmax', '30',
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'main',
            '-b:v', $video.'k', '-maxrate', $video.'k', '-bufsize', ($video * 2).'k',
            '-threads', (string) max(1, (int) config('media.video.threads', 2)),
            ...($hasAudio
                ? ['-c:a', 'aac', '-b:a', $plan['audio_kbps'].'k', '-ac', '2']
                : ['-an']),
            '-sn', '-dn', '-map_metadata', '-1',
            '-movflags', '+faststart',
            $output,
        ];

        try {
            $result = Process::timeout(max(60, (int) config('media.video.timeout_seconds', 900)))->run($args);
        } catch (ProcessTimedOutException) {
            Log::error('VideoCompressor: ffmpeg ran out of time', [
                'timeout' => config('media.video.timeout_seconds'),
                'video_kbps' => $video,
            ]);

            return false;
        }

        if (! $result->successful() || ! is_file($output)) {
            Log::error('VideoCompressor: ffmpeg failed', [
                'exit' => $result->exitCode(),
                'ffmpeg' => mb_substr(trim($result->errorOutput()), -500),
            ]);

            return false;
        }

        return true;
    }

    /** `nice -n 10` where it exists: an encode must not slow down the webhooks beside it. */
    private function nice(): array
    {
        foreach (['/usr/bin/nice', '/bin/nice'] as $nice) {
            if (is_executable($nice)) {
                return [$nice, '-n', '10'];
            }
        }

        return [];
    }

    private function ffmpeg(): string
    {
        $configured = config('media.ffmpeg_path');

        return is_string($configured) && $configured !== '' ? $configured : 'ffmpeg';
    }

    private function ffprobe(): string
    {
        $configured = config('media.ffprobe_path');

        return is_string($configured) && $configured !== '' ? $configured : 'ffprobe';
    }

    private static function runnable(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)), $output, $status);

        return $status === 0;
    }
}
