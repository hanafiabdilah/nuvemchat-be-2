<?php

namespace App\Services\Message;

use App\Exceptions\UserFacingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Re-encodes audio a channel will not accept into audio it will.
 *
 * Recording in a browser is not a choice the product gets to make: Chrome and
 * Edge hand back WebM and nothing else, Firefox gives Ogg/Opus, Safari gives
 * MP4. Only one of those is a container the WhatsApp Cloud API accepts, so a
 * voice note recorded by an agent on the most common browser in the world is
 * rejected at upload unless something in between rewrites it. That something
 * is this class.
 *
 * ⚠️ It shells out to ffmpeg, so the feature is only ever as present as the
 * binary. That is why the failure has its own sentence rather than being left
 * to the channel: Meta's answer to a WebM upload is an error code about a MIME
 * type, which tells the agent nothing they can act on, whereas "send an .mp3"
 * is something they can do in the next ten seconds.
 *
 * Two handlers (API Way, Instagram) call ffmpeg inline and predate this; they
 * are no worse off for it, but new callers belong here.
 */
class AudioNormalizer
{
    /**
     * Voice-grade Opus. WhatsApp's own voice notes live in this range, and the
     * 16 MB ceiling on an upload is a real constraint for a long recording.
     * Music is not a concern here: it arrives as mp3/m4a, which the Cloud API
     * already accepts, and accepted formats are never sent through this class.
     */
    private const BITRATE = '32k';

    /**
     * One sentence for every way this can fail, on purpose. The agent's remedy
     * is identical whether the binary is missing or the file was unreadable,
     * and the difference between those two belongs in the log, where someone
     * can act on it.
     */
    public const FAILURE_MESSAGE = 'Não foi possível converter este áudio para um formato que o WhatsApp aceita. Tente enviar um arquivo .mp3, .m4a ou .ogg.';

    public const FAILURE_CODE = 'audio_conversion_failed';

    private ?bool $available = null;

    /**
     * Whether ffmpeg can be run at all.
     *
     * Kept separate from the conversion so a caller can decide *before*
     * committing to a path that needs it — and so the reason a send failed is
     * "the converter is not installed" in the log rather than an exit status.
     */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $binary = $this->binary();

        if (str_contains($binary, '/')) {
            return $this->available = is_file($binary) && is_executable($binary);
        }

        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)), $output, $status);

        return $this->available = $status === 0;
    }

    /**
     * Re-encode to mono Ogg/Opus and hand back the result as an UploadedFile.
     *
     * ⚠️ The caller owns the returned file. It is a temp file this method
     * created, not one PHP will clean up with the request, so the send path
     * that consumes it has to unlink it — a 16 MB leak per voice note inside a
     * long-running queue worker is slow enough to go unnoticed for months.
     */
    public function toOggOpus(UploadedFile $file): UploadedFile
    {
        if (! $this->available()) {
            Log::error('AudioNormalizer: ffmpeg is not installed, so audio cannot be converted', [
                'binary' => $this->binary(),
                'source_extension' => strtolower($file->getClientOriginalExtension()),
            ]);

            throw $this->failure();
        }

        $output = sys_get_temp_dir() . '/' . uniqid('audio_opus_', true) . '.ogg';

        // -vn, because a .webm or .mp4 can carry a video track and this is the
        //   audio endpoint; keeping it only inflates a file with a hard cap.
        // -ac 1, because Meta accepts Ogg/Opus as "mono input only".
        $command = sprintf(
            '%s -hide_banner -loglevel error -y -i %s -vn -ac 1 -c:a libopus -b:a %s %s 2>&1',
            escapeshellarg($this->binary()),
            escapeshellarg($file->getRealPath()),
            self::BITRATE,
            escapeshellarg($output),
        );

        exec($command, $lines, $status);

        if ($status !== 0 || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);

            Log::error('AudioNormalizer: ffmpeg failed to convert the audio', [
                'status' => $status,
                // The tail only: ffmpeg's banner is suppressed, but a codec
                // failure still prints more than a log line should carry.
                'ffmpeg' => implode(' | ', array_slice($lines, -5)),
                'source_extension' => strtolower($file->getClientOriginalExtension()),
            ]);

            throw $this->failure();
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'audio';

        return new UploadedFile(
            $output,
            $name . '.ogg',
            'audio/ogg',
            null,
            true, // already "uploaded": skip the is_uploaded_file() check
        );
    }

    /**
     * 422 with our own words: nothing upstream has been asked yet, and a second
     * identical attempt fails identically.
     */
    private function failure(): UserFacingException
    {
        return new UserFacingException(self::FAILURE_MESSAGE, 422, self::FAILURE_CODE);
    }

    private function binary(): string
    {
        $configured = config('media.ffmpeg_path');

        return is_string($configured) && $configured !== '' ? $configured : 'ffmpeg';
    }
}
