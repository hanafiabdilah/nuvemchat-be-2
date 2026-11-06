<?php

namespace App\Exceptions;

/**
 * A video that could not be made small enough for its channel.
 *
 * Carries a reason code as well as a sentence, because the sentence is shown
 * twice in two places: in the composer's toast (Portuguese, our copy) and in the
 * thread as a system note, which the dashboard renders in the reader's own
 * language from `code` + `maxSeconds`.
 */
class VideoCompressionException extends UserFacingException
{
    /** The video is longer than any watchable bitrate can fit in the channel's limit. */
    public const TOO_LONG = 'video_too_long';

    /** ffprobe could not find a duration or a picture in the file. */
    public const UNREADABLE = 'video_unreadable';

    /** ffmpeg failed, ran out of time, or could not get under the limit. */
    public const FAILED = 'video_compression_failed';

    /** ffmpeg or ffprobe is not installed. */
    public const UNAVAILABLE = 'video_compression_unavailable';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $maxSeconds = null,
    ) {
        parent::__construct($message, 422, $reason);
    }

    public static function tooLong(int $maxSeconds): self
    {
        $minutes = max(1, intdiv($maxSeconds, 60));

        return new self(
            "Este vídeo é longo demais para este canal. Envie um vídeo de até {$minutes} min ou compartilhe um link.",
            self::TOO_LONG,
            $maxSeconds,
        );
    }

    public static function unreadable(): self
    {
        return new self('Não foi possível ler este vídeo. Tente outro arquivo (MP4 ou MOV).', self::UNREADABLE);
    }

    public static function failed(): self
    {
        return new self('Não foi possível comprimir este vídeo para o tamanho que este canal aceita. Tente um vídeo mais curto.', self::FAILED);
    }

    public static function unavailable(): self
    {
        return new self('Este vídeo é maior do que o canal aceita e não pode ser comprimido agora. Envie um vídeo menor.', self::UNAVAILABLE);
    }
}
