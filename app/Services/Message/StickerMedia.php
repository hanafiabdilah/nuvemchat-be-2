<?php

namespace App\Services\Message;

use App\Enums\Connection\Channel;
use App\Exceptions\UserFacingException;
use App\Services\Media\StickerImage;
use Illuminate\Http\UploadedFile;

/**
 * What a handler actually sends when asked for a sticker.
 *
 * The file is always looked at, even when it arrives as a URL that already
 * ends in `.webp`: a WebP of the wrong size is still a refusal from WhatsApp,
 * and a sticker is small enough (≤500 KB) that reading it costs nothing next
 * to a send that fails in front of the customer. What the look decides is only
 * which road it takes:
 *
 *  - already a sticker → the URL itself, so the channel fetches it and no copy
 *    of it is stored per message — the same fast path every other media send
 *    takes;
 *  - anything else → the converted bytes, uploaded like an attached file.
 *
 * Every refusal here is a UserFacingException: nothing upstream has been
 * asked yet, a second identical attempt fails identically, and the sentence
 * says what to do instead.
 */
final class StickerMedia
{
    /**
     * Channels with no sticker that still take a WebP as a picture. The rest
     * (Instagram, Messenger) are sent the same picture as a PNG.
     */
    private const WEBP_AS_IMAGE = [
        Channel::Discord,
        Channel::TikTok,
        Channel::LiveChatWidget,
        Channel::Email,
    ];

    /** The sticker, ready for a channel that has stickers. */
    public static function prepare(?OutboundMedia $media): OutboundMedia
    {
        [$media, $bytes] = self::read($media);

        if (StickerImage::isReady($bytes) && $media->isUrl()) {
            return $media;
        }

        $webp = StickerImage::toWebp($bytes);

        if ($webp === null) {
            throw self::unreadable();
        }

        return OutboundMedia::fromFile(self::file($webp, $media->filename, 'webp', 'image/webp'));
    }

    /**
     * The handler `$data` for sending the sticker as a plain picture, on a
     * channel that has no stickers. No caption: a sticker never has one, and
     * the picture standing in for it should not grow one either.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function asImageData(array $data, Channel $channel): array
    {
        $media = OutboundMedia::fromData($data, 'sticker');
        $image = ['message' => null] + array_intersect_key($data, ['replied_message_id' => true]);

        if (in_array($channel, self::WEBP_AS_IMAGE, true)) {
            // E-mail is the one of these that only takes a file.
            if ($media?->isUrl() && $channel !== Channel::Email) {
                return $image + ['media_url' => $media->url];
            }

            [$media, $bytes] = self::read($media);
            $webp = StickerImage::toWebp($bytes) ?? throw self::unreadable();

            return $image + ['image' => self::file($webp, $media->filename, 'webp', 'image/webp')];
        }

        [$media, $bytes] = self::read($media);

        if (StickerImage::isAnimated($bytes)) {
            throw new UserFacingException(
                'Este canal não aceita figurinhas animadas. Use uma figurinha estática nesta conexão.',
                422,
                'sticker_animated_unsupported',
            );
        }

        $png = StickerImage::toPng($bytes) ?? throw self::unreadable();

        return $image + ['image' => self::file($png, $media->filename, 'png', 'image/png')];
    }

    /**
     * @return array{0: OutboundMedia, 1: string}
     */
    private static function read(?OutboundMedia $media): array
    {
        if ($media === null) {
            throw new UserFacingException('Escolha a imagem da figurinha antes de enviar.', 422, 'sticker_missing');
        }

        $file = $media->toUploadedFile();
        $bytes = $file ? @file_get_contents($file->getRealPath()) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new UserFacingException(
                'Não foi possível baixar a imagem da figurinha. Confira se o arquivo ainda existe.',
                422,
                'sticker_unavailable',
            );
        }

        return [$media, $bytes];
    }

    private static function unreadable(): UserFacingException
    {
        return new UserFacingException(
            'Este arquivo não pode ser enviado como figurinha. Use uma imagem PNG, JPG ou WebP.',
            422,
            'sticker_unreadable',
        );
    }

    /**
     * Bytes as an "already uploaded" file, named after the original with the
     * extension of what it now is — that name is what gets stored and shown.
     */
    private static function file(string $bytes, string $originalName, string $extension, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sticker_');
        file_put_contents($path, $bytes);

        $name = pathinfo($originalName, PATHINFO_FILENAME) ?: 'sticker';

        return new UploadedFile($path, $name.'.'.$extension, $mime, null, true);
    }
}
