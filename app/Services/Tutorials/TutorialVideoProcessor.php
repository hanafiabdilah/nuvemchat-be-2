<?php

namespace App\Services\Tutorials;

use App\Exceptions\TutorialVideoException;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Turns an uploaded tutorial into a file that streams well, plus its poster.
 *
 * What "streams well" means here, and why each step:
 *
 * - **faststart.** An MP4 straight from a phone or a screen recorder keeps its
 *   index (the `moov` atom) at the end, so the browser has to download the
 *   whole file before the first frame. Moving it to the front is a copy, not
 *   an encode, and it is the difference between "plays now" and "plays after
 *   a minute of spinner".
 * - **H.264 + AAC, at most 1080p and ~6 Mbps.** What every browser plays, at a
 *   size that every play — a download from our storage — can afford. A file
 *   already in that shape is only remuxed; anything else (HEVC from an
 *   iPhone, WebM, a 4K recording) is re-encoded once, here, instead of
 *   failing to play for some viewers forever.
 * - **A poster.** Without one the list shows black boxes and the player a
 *   black frame until it buffers. ffmpeg's `thumbnail` filter picks a
 *   representative frame instead of the first one, which is usually a fade.
 *
 * Without ffmpeg the upload is published as it came: it may still play (most
 * uploads are MP4) and the Back Office says why there is no poster. Without
 * ffprobe being able to read it, it is not a video, and that is a failure.
 */
class TutorialVideoProcessor
{
    private ?bool $available = null;

    /** Whether ffmpeg and ffprobe can both be run. */
    public function available(): bool
    {
        return $this->available ??= self::runnable($this->ffmpeg()) && self::runnable($this->ffprobe());
    }

    /**
     * Process `$sourcePath` (on the staging disk, where the Back Office's
     * chunked upload assembled it) into files on the published disk.
     *
     * @return array{path: string, poster_path: ?string, duration_seconds: ?int, width: ?int, height: ?int, size_bytes: int, note: ?string}
     *
     * @throws TutorialVideoException when the file is not a readable video
     */
    public function process(string $sourcePath): array
    {
        $disk = MediaStorage::published();
        $input = MediaStorage::staging()->path($sourcePath);

        if (! is_file($input)) {
            throw new TutorialVideoException('The uploaded file is no longer on the server. Upload it again.');
        }

        $directory = 'tutorials/'.MediaFilename::token();

        if (! $this->available()) {
            Log::warning('Tutorial video published without processing: ffmpeg or ffprobe is not available', ['path' => $sourcePath]);

            $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'mp4';
            $disk->putFileAs($directory, new File($input), 'video.'.$extension);

            return [
                'path' => $directory.'/video.'.$extension,
                'poster_path' => null,
                'duration_seconds' => null,
                'width' => null,
                'height' => null,
                'size_bytes' => (int) filesize($input),
                'note' => 'ffmpeg is not installed on the server: the file was published as uploaded, without a poster.',
            ];
        }

        $work = storage_path('app/tutorial-processing/'.MediaFilename::token());
        if (! is_dir($work)) {
            mkdir($work, 0775, true);
        }

        try {
            $probe = $this->probe($input);

            if ($probe === null) {
                throw new TutorialVideoException('This file could not be read as a video. Export it as MP4 (H.264) and upload it again.');
            }

            $output = $work.'/video.mp4';
            $ok = $this->needsEncode($probe)
                ? $this->encode($input, $output, $probe['has_audio'])
                : $this->remux($input, $output);

            // A remux that fails (an odd container, a stream copy refuses) is
            // retried as an encode: slower, but it is the one that always works.
            if (! $ok && ! $this->needsEncode($probe)) {
                $ok = $this->encode($input, $output, $probe['has_audio']);
            }

            if (! $ok || ! is_file($output) || filesize($output) === 0) {
                throw new TutorialVideoException('The video could not be converted for playback. Export it as MP4 (H.264) and upload it again.');
            }

            $final = $this->probe($output) ?? $probe;
            $poster = $work.'/poster.jpg';
            $hasPoster = $this->poster($output, $poster, $final['duration']);

            $disk->putFileAs($directory, new File($output), 'video.mp4');
            if ($hasPoster) {
                $disk->putFileAs($directory, new File($poster), 'poster.jpg');
            }

            return [
                'path' => $directory.'/video.mp4',
                'poster_path' => $hasPoster ? $directory.'/poster.jpg' : null,
                'duration_seconds' => (int) round($final['duration']),
                'width' => $final['width'],
                'height' => $final['height'],
                'size_bytes' => (int) filesize($output),
                'note' => $hasPoster ? null : 'The poster could not be drawn from the video.',
            ];
        } finally {
            $this->removeDirectory($work);
        }
    }

    /**
     * Duration, displayed dimensions and the facts that decide encode vs copy.
     * Null when the file has no readable duration or no picture.
     *
     * @return array{duration: float, width: ?int, height: ?int, has_audio: bool, video_codec: ?string, audio_codec: ?string, pix_fmt: ?string, format: string, bit_rate: int}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(60)->run([
            $this->ffprobe(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        if (! $result->successful()) {
            Log::warning('TutorialVideoProcessor: ffprobe could not read the video', [
                'ffprobe' => mb_substr(trim($result->errorOutput()), -500),
            ]);

            return null;
        }

        $json = json_decode($result->output(), true);
        $streams = is_array($json['streams'] ?? null) ? $json['streams'] : [];

        // Cover art in an MP4 is a "video" stream too; it is not the picture.
        $video = collect($streams)->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'video'
            && (int) ($stream['disposition']['attached_pic'] ?? 0) === 0);
        $audio = collect($streams)->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'audio');

        $duration = (float) ($json['format']['duration'] ?? $video['duration'] ?? 0);

        if ($video === null || $duration <= 0) {
            return null;
        }

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        // A phone records landscape pixels and a "rotate 90" note; the player
        // honours the note, so the size people see is the swapped one.
        if ($width && $height && abs($this->rotation($video)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'has_audio' => $audio !== null,
            'video_codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'pix_fmt' => $video['pix_fmt'] ?? null,
            'format' => (string) ($json['format']['format_name'] ?? ''),
            'bit_rate' => (int) ($json['format']['bit_rate'] ?? 0),
        ];
    }

    /** @param array{duration: float, width: ?int, height: ?int, has_audio: bool, video_codec: ?string, audio_codec: ?string, pix_fmt: ?string, format: string, bit_rate: int} $probe */
    public function needsEncode(array $probe): bool
    {
        $longSide = max((int) $probe['width'], (int) $probe['height']);

        return ! str_contains($probe['format'], 'mp4')
            || $probe['video_codec'] !== 'h264'
            || ! in_array($probe['pix_fmt'], ['yuv420p', 'yuvj420p'], true)
            || ($probe['audio_codec'] !== null && ! in_array($probe['audio_codec'], ['aac', 'mp3'], true))
            || $longSide > (int) config('tutorials.max_long_side', 1920)
            || ($probe['bit_rate'] > 0 && $probe['bit_rate'] > (int) config('tutorials.max_bitrate', 6_000_000));
    }

    private function remux(string $input, string $output): bool
    {
        return $this->ffmpegRun([
            '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-c', 'copy',
            '-movflags', '+faststart',
            $output,
        ], 'remux');
    }

    private function encode(string $input, string $output, bool $hasAudio): bool
    {
        $max = max(320, (int) config('tutorials.max_long_side', 1920));
        // Long side capped, aspect kept, both sides even (H.264 needs it).
        // ffmpeg has already applied the rotation note when the filter runs,
        // so iw/ih are the sides people see.
        $scale = "scale='if(gte(iw,ih),trunc(min({$max},iw)/2)*2,-2)':'if(gte(iw,ih),-2,trunc(min({$max},ih)/2)*2)'";
        $maxrate = max(1, intdiv((int) config('tutorials.max_bitrate', 6_000_000), 1000)).'k';

        $arguments = [
            '-i', $input,
            '-map', '0:v:0',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-maxrate', $maxrate, '-bufsize', $maxrate,
            '-vf', $scale,
        ];

        if ($hasAudio) {
            $arguments = [...$arguments, '-map', '0:a:0', '-c:a', 'aac', '-b:a', '128k', '-ac', '2'];
        }

        return $this->ffmpegRun([
            ...$arguments,
            '-movflags', '+faststart',
            '-threads', (string) max(1, (int) config('media.video.threads', 2)),
            $output,
        ], 'encode');
    }

    private function poster(string $video, string $poster, float $duration): bool
    {
        // A little way in: the first frames are usually a fade or a title.
        $at = number_format(min(2.0, max(0.0, $duration * 0.1)), 2, '.', '');

        $ok = $this->ffmpegRun([
            '-ss', $at,
            '-i', $video,
            '-frames:v', '1',
            '-vf', "thumbnail=30,scale='trunc(min(1280,iw)/2)*2':-2",
            '-q:v', '3',
            $poster,
        ], 'poster');

        return $ok && is_file($poster) && filesize($poster) > 0;
    }

    private function ffmpegRun(array $arguments, string $step): bool
    {
        $result = Process::timeout((int) config('media.video.timeout_seconds', 900))->run([
            ...$this->nice(),
            $this->ffmpeg(), '-y', '-hide_banner', '-loglevel', 'error',
            ...$arguments,
        ]);

        if (! $result->successful()) {
            Log::warning('TutorialVideoProcessor: ffmpeg '.$step.' failed', [
                'ffmpeg' => mb_substr(trim($result->errorOutput()), -800),
            ]);

            return false;
        }

        return true;
    }

    private function rotation(array $stream): int
    {
        foreach ($stream['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                return (int) $side['rotation'];
            }
        }

        return (int) ($stream['tags']['rotate'] ?? 0);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
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
