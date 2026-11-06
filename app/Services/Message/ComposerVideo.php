<?php

namespace App\Services\Message;

use App\Enums\Gallery\AssetType;
use App\Exceptions\VideoCompressionException;
use App\Jobs\SendCompressedVideo;
use App\Models\Conversation;
use App\Models\GalleryAsset;
use App\Models\User;
use App\Services\Gallery\GalleryMediaResolver;
use App\Services\Media\VideoCompressor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Decides how a video from the composer is sent: right now, or compressed first.
 *
 * The composer takes far bigger videos than any channel does (200 MB against
 * 10–50 MB, Channel::videoMaxBytes()). A file that fits is sent in the
 * request, exactly as before. One that does not is handed to
 * SendCompressedVideo and the request answers 202 — the agent sees the bubble
 * "being prepared" until the job sends it or writes a note saying why not.
 *
 * Three places a video can come from, all measured before anything is sent:
 * an upload token (the file came in pieces, see StagedVideoUploads), a file in
 * the request itself, or a gallery asset.
 */
final class ComposerVideo
{
    public function __construct(
        private readonly StagedVideoUploads $staged,
        private readonly VideoCompressor $compressor,
    ) {}

    /**
     * @return array{queued: true, client_ref: string, original_bytes: int, limit_bytes: int}
     *                                                                                        |array{queued: false, data: array<string, mixed>, staged_path: ?string}
     *
     * @throws ValidationException|VideoCompressionException
     */
    public function prepare(Conversation $conversation, User $user, Request $request): array
    {
        $request->validate([
            'upload_token' => ['nullable', 'string', 'max:64'],
            'client_ref' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'message' => ['nullable', 'string'],
            'replied_message_id' => ['nullable', 'integer'],
        ]);

        $data = $request->all();
        $limit = $conversation->connection->channel->videoMaxBytes();
        $staged = null;
        $asset = null;

        if ($request->filled('upload_token')) {
            $staged = $this->staged->find((string) $request->input('upload_token'), $user, $conversation);

            if ($staged === null) {
                throw ValidationException::withMessages([
                    'upload_token' => 'O envio deste vídeo expirou. Selecione o vídeo e envie de novo.',
                ]);
            }

            $size = $staged['size'];
        } elseif ($request->file('video') instanceof UploadedFile) {
            $size = (int) $request->file('video')->getSize();
        } elseif ($request->filled('gallery_asset_id')) {
            $asset = GalleryAsset::where('tenant_id', $user->tenant_id)
                ->where('type', AssetType::Video)
                ->find((int) $request->input('gallery_asset_id'));

            // Not this resolver's to refuse: GalleryMediaResolver says what is
            // wrong with an id it cannot use.
            $size = (int) ($asset?->size_bytes ?? 0);
        } else {
            $size = 0;
        }

        if (! VideoCompressor::needsCompression($size, $limit)) {
            if ($staged !== null) {
                $data['video'] = StagedVideoUploads::asUploadedFile($staged);
                unset($data['upload_token']);
                $this->staged->forgetToken((string) $request->input('upload_token'));
            }

            return ['queued' => false, 'data' => $data, 'staged_path' => $staged['path'] ?? null];
        }

        if (! $this->compressor->available()) {
            throw VideoCompressionException::unavailable();
        }

        $source = match (true) {
            $staged !== null => ['kind' => 'staged', 'path' => $staged['path'], 'name' => $staged['name'], 'size' => $size],
            $asset !== null => ['kind' => 'gallery', 'asset_id' => $asset->id, 'name' => $asset->public_filename ?: 'video.mp4', 'size' => $size],
            default => ['kind' => 'staged', ...StagedVideoUploads::keep($request->file('video'), (int) $user->tenant_id)],
        };

        if ($staged !== null) {
            $this->staged->forgetToken((string) $request->input('upload_token'));
        }

        if ($asset !== null) {
            // The same ownership check and "last used" stamp as a library file
            // sent straight away; the URL it mints is not needed here.
            app(GalleryMediaResolver::class)->apply(['gallery_asset_id' => $asset->id], $user->tenant, AssetType::Video);
        }

        $clientRef = (string) ($request->input('client_ref') ?: Str::uuid());

        SendCompressedVideo::dispatch(
            $conversation->id,
            $user->id,
            $source,
            [
                'message' => $request->input('message'),
                'view_once' => $request->boolean('view_once'),
                'replied_message_id' => $request->filled('replied_message_id') ? (int) $request->input('replied_message_id') : null,
            ],
            $clientRef,
        );

        return [
            'queued' => true,
            'client_ref' => $clientRef,
            'original_bytes' => $size,
            'limit_bytes' => (int) $limit,
        ];
    }

    /**
     * Take one piece of a video headed for this conversation; the last one
     * answers with the token the send request names.
     *
     * @return array{done: false, next_index: int}|array{done: true, upload_token: string, name: string, size: int, limit_bytes: ?int}
     */
    public function receivePiece(Conversation $conversation, User $user, array $piece, UploadedFile $chunk): array
    {
        $result = $this->staged->chunks()->receive(
            (int) $user->tenant_id,
            StagedVideoUploads::directory((int) $user->tenant_id),
            $piece['upload_id'],
            (int) $piece['index'],
            (int) $piece['total'],
            (int) $piece['size'],
            $piece['name'],
            $chunk,
        );

        if (! $result['done']) {
            return ['done' => false, 'next_index' => $result['next_index']];
        }

        return [
            'done' => true,
            'upload_token' => $this->staged->remember($user, $conversation, $result['file']),
            'name' => $result['file']['name'],
            'size' => (int) $result['file']['size'],
            'limit_bytes' => $conversation->connection->channel->videoMaxBytes(),
        ];
    }
}
