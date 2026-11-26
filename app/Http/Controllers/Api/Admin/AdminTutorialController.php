<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Tutorial\TutorialTopic;
use App\Enums\Tutorial\TutorialVideoStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTutorialVideo;
use App\Models\AuditLog;
use App\Models\TutorialVideo;
use App\Models\TutorialVideoTopic;
use App\Services\Media\ChunkedUpload;
use App\Services\Media\MediaStorage;
use App\Services\Message\StagedVideoUploads;
use App\Services\Tutorials\TutorialThumbnail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Back Office → Tutorials: the library of videos every workspace's dashboard
 * shows next to the feature each one explains.
 *
 * Uploading is two steps, because the file is large and the form is not:
 * the browser sends the video in 8 MB pieces to `uploads` (ChunkedUpload),
 * gets back an opaque token, and the form's Save sends that token with the
 * title and topics. The token, not a path, is what crosses the wire — a path
 * a client can choose is a file on our disk it can choose.
 */
class AdminTutorialController extends Controller
{
    private const EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'mkv'];

    /** How long an uploaded file waits for the form that names it. */
    private const UPLOAD_TOKEN_HOURS = 12;

    public function meta(): JsonResponse
    {
        $areas = [];
        foreach (TutorialTopic::AREAS as $key => $label) {
            $areas[] = ['key' => $key, 'label' => $label];
        }

        $locales = [];
        foreach ((array) config('markets.locales', []) as $code => $label) {
            $locales[] = ['code' => $code, 'label' => $label];
        }

        return response()->json([
            'areas' => $areas,
            'topics' => array_map(fn (TutorialTopic $topic) => $topic->describe(), TutorialTopic::cases()),
            'locales' => $locales,
            'fallback_locale' => config('tutorials.fallback_locale', 'pt_BR'),
            'extensions' => self::EXTENSIONS,
            'max_upload_mb' => $this->maxBytes() / 1024 / 1024,
            'chunk_bytes' => ChunkedUpload::CHUNK_BYTES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'topic' => ['nullable', 'string', Rule::in(TutorialTopic::values())],
            'locale' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:160'],
        ]);

        $query = TutorialVideo::query()
            ->with(['topics', 'creator:id,name'])
            ->withCount([
                'views',
                'views as completions_count' => fn ($q) => $q->whereNotNull('completed_at'),
            ]);

        if (! empty($filters['locale'])) {
            $query->where('locale', $filters['locale']);
        }

        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%');
        }

        if (! empty($filters['topic'])) {
            // In the order that topic shows them — the list is where they are
            // dragged into that order.
            $query->join('tutorial_video_topics as placement', function ($join) use ($filters) {
                $join->on('placement.tutorial_video_id', '=', 'tutorial_videos.id')
                    ->where('placement.topic', '=', $filters['topic']);
            })
                ->select('tutorial_videos.*')
                ->orderBy('placement.position')
                ->orderBy('tutorial_videos.id');
        } else {
            $query->latest('tutorial_videos.id');
        }

        return response()->json([
            'data' => $query->limit(500)->get()->map(fn (TutorialVideo $video) => $this->present($video))->values(),
        ]);
    }

    public function show(TutorialVideo $video): JsonResponse
    {
        $video->load(['topics', 'creator:id,name'])->loadCount([
            'views',
            'views as completions_count' => fn ($q) => $q->whereNotNull('completed_at'),
        ]);

        return response()->json(['data' => $this->present($video)]);
    }

    /** One piece of a video file (see ChunkedUpload). */
    public function uploadChunk(Request $request): JsonResponse
    {
        $piece = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100000'],
            'size' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:'.(intdiv(ChunkedUpload::CHUNK_BYTES, 1024) + 64)],
        ]);

        // Platform content has no workspace; 0 keeps its half-sent pieces in a
        // folder of their own.
        $result = (new ChunkedUpload(
            'tutorials',
            self::EXTENSIONS,
            $this->maxBytes(),
            MediaStorage::staging(),
            'video/',
            'Choose a video file (MP4, MOV or WEBM).',
        ))->receive(
            0,
            'tutorial-uploads',
            $piece['upload_id'],
            (int) $piece['index'],
            (int) $piece['total'],
            (int) $piece['size'],
            $piece['name'],
            $request->file('chunk'),
        );

        if (! $result['done']) {
            return response()->json(['data' => ['done' => false, 'next_index' => $result['next_index']]]);
        }

        $token = Str::random(40);
        Cache::put($this->uploadKey($token), [
            'path' => $result['file']['path'],
            'name' => $piece['name'],
            'size' => $result['file']['size'],
            'admin_id' => $request->user()->getKey(),
        ], now()->addHours(self::UPLOAD_TOKEN_HOURS));

        return response()->json(['data' => [
            'done' => true,
            'upload' => ['token' => $token, 'name' => $piece['name'], 'size' => $result['file']['size']],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'upload_token' => ['required', 'string', 'max:64'],
        ]);

        $upload = $this->takeUpload($request, $data['upload_token']);

        $video = DB::transaction(function () use ($data, $upload, $request) {
            $video = TutorialVideo::create([
                'title' => trim($data['title']),
                'description' => $this->cleanDescription($data['description'] ?? null),
                'locale' => $data['locale'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'auto_open' => (bool) ($data['auto_open'] ?? false),
                'status' => TutorialVideoStatus::Processing,
                'source_path' => $upload['path'],
                'original_name' => mb_substr($upload['name'], 0, 255),
                'created_by_admin_id' => $request->user()->getKey(),
            ]);

            $this->syncTopics($video, $data['topics']);

            return $video;
        });

        ProcessTutorialVideo::dispatch($video->id, $upload['path']);

        AuditLog::record('tutorials.create', "Added tutorial video \"{$video->title}\"", [
            'tutorial_video_id' => $video->id,
            'topics' => $data['topics'],
            'locale' => $video->locale,
        ]);

        return $this->show($video->refresh())->setStatusCode(201);
    }

    public function update(Request $request, TutorialVideo $video): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(sometimes: true),
            'upload_token' => ['nullable', 'string', 'max:64'],
        ]);

        $upload = ! empty($data['upload_token']) ? $this->takeUpload($request, $data['upload_token']) : null;
        $previousSource = $video->source_path;

        DB::transaction(function () use ($video, $data, $upload) {
            $changes = [];

            if (array_key_exists('title', $data)) {
                $changes['title'] = trim($data['title']);
            }
            if (array_key_exists('description', $data)) {
                $changes['description'] = $this->cleanDescription($data['description']);
            }
            foreach (['locale', 'is_active', 'auto_open'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $data[$field];
                }
            }

            if ($upload) {
                // A video that never became playable starts over; one that
                // plays keeps playing its old file until the new one is ready.
                $changes['source_path'] = $upload['path'];
                $changes['original_name'] = mb_substr($upload['name'], 0, 255);
                $changes['error'] = null;
                if ($video->path === null) {
                    $changes['status'] = TutorialVideoStatus::Processing;
                }
            }

            $video->update($changes);

            if (array_key_exists('topics', $data)) {
                $this->syncTopics($video, $data['topics']);
            }
        });

        if ($upload) {
            // The upload it replaces, if one was still waiting.
            if ($previousSource && $previousSource !== $upload['path']) {
                StagedVideoUploads::delete($previousSource);
            }

            ProcessTutorialVideo::dispatch($video->id, $upload['path']);
        }

        AuditLog::record('tutorials.update', "Updated tutorial video \"{$video->title}\"", [
            'tutorial_video_id' => $video->id,
            'fields' => array_keys(array_diff_key($data, ['upload_token' => true])),
            'new_file' => $upload !== null,
        ]);

        return $this->show($video->refresh());
    }

    public function destroy(TutorialVideo $video): JsonResponse
    {
        $title = $video->title;
        $published = [$video->path, $video->poster_path, $video->thumbnail_path];
        $source = $video->source_path;

        $video->delete();

        ProcessTutorialVideo::deleteFiles($published);
        if ($source) {
            StagedVideoUploads::delete($source);
        }

        AuditLog::record('tutorials.delete', "Deleted tutorial video \"{$title}\"", ['tutorial_video_id' => $video->id]);

        return response()->json(['message' => 'Tutorial video deleted.']);
    }

    /**
     * The picture shown before the video plays, in place of the frame drawn
     * from it. Optional; replacing the video file keeps it.
     */
    public function storeThumbnail(Request $request, TutorialVideo $video, TutorialThumbnail $thumbnails): JsonResponse
    {
        $request->validate(['thumbnail' => TutorialThumbnail::rules()]);

        $thumbnails->store($video, $request->file('thumbnail'));

        AuditLog::record('tutorials.thumbnail', "Set the thumbnail of tutorial video \"{$video->title}\"", [
            'tutorial_video_id' => $video->id,
        ]);

        return $this->show($video->refresh());
    }

    /** Back to the frame drawn from the video. */
    public function destroyThumbnail(TutorialVideo $video, TutorialThumbnail $thumbnails): JsonResponse
    {
        $thumbnails->clear($video);

        AuditLog::record('tutorials.thumbnail', "Removed the thumbnail of tutorial video \"{$video->title}\"", [
            'tutorial_video_id' => $video->id,
        ]);

        return $this->show($video->refresh());
    }

    /** The order one topic shows its videos in, as dragged in the list. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(TutorialTopic::values())],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $placements = TutorialVideoTopic::query()->where('topic', $data['topic'])->get()->keyBy('tutorial_video_id');

        $unknown = array_diff($data['ids'], $placements->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['ids' => 'Some of these videos are not on this topic any more. Reload the list.']);
        }

        DB::transaction(function () use ($data, $placements) {
            foreach (array_values($data['ids']) as $position => $id) {
                $placements[$id]->update(['position' => $position]);
            }

            // Anything on the topic the request did not list goes after.
            $next = count($data['ids']);
            foreach ($placements as $id => $placement) {
                if (! in_array($id, $data['ids'], true)) {
                    $placement->update(['position' => $next++]);
                }
            }
        });

        AuditLog::record('tutorials.reorder', "Reordered tutorial videos on {$data['topic']}", [
            'topic' => $data['topic'],
            'ids' => $data['ids'],
        ]);

        return response()->json(['message' => 'Order saved.']);
    }

    private function rules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:160', 'not_regex:/^\s*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'locale' => [$required, 'string', Rule::in(array_keys((array) config('markets.locales', [])))],
            'topics' => [$required, 'array', 'min:1', 'max:'.count(TutorialTopic::cases())],
            'topics.*' => ['string', 'distinct', Rule::in(TutorialTopic::values())],
            'is_active' => ['sometimes', 'boolean'],
            'auto_open' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Keep the positions of topics the video stays on; put it last on the
     * topics it joins.
     *
     * @param  list<string>  $topics
     */
    private function syncTopics(TutorialVideo $video, array $topics): void
    {
        $topics = array_values(array_unique($topics));

        $video->topics()->whereNotIn('topic', $topics)->delete();
        $existing = $video->topics()->pluck('topic')->all();

        foreach (array_diff($topics, $existing) as $topic) {
            $last = TutorialVideoTopic::query()->where('topic', $topic)->max('position');

            $video->topics()->create([
                'topic' => $topic,
                'position' => $last === null ? 0 : (int) $last + 1,
            ]);
        }
    }

    /** @return array{path: string, name: string, size: int} */
    private function takeUpload(Request $request, string $token): array
    {
        $upload = Cache::pull($this->uploadKey($token));

        if (! is_array($upload) || ($upload['admin_id'] ?? null) !== $request->user()->getKey()) {
            throw ValidationException::withMessages([
                'upload_token' => 'This upload expired or belongs to someone else. Choose the file again.',
            ]);
        }

        return $upload;
    }

    private function uploadKey(string $token): string
    {
        return 'tutorial-upload:'.hash('sha256', $token);
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : $description;
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('tutorials.max_upload_mb', 500)) * 1024 * 1024;
    }

    private function present(TutorialVideo $video): array
    {
        return [
            'id' => $video->id,
            'title' => $video->title,
            'description' => $video->description,
            'locale' => $video->locale,
            'is_active' => $video->is_active,
            'auto_open' => $video->auto_open,
            'status' => $video->status->value,
            // A new file is being prepared (for a video that may still be
            // playing its old one).
            'processing' => $video->source_path !== null,
            'error' => $video->error,
            'original_name' => $video->original_name,
            'video_url' => $video->videoUrl(),
            // What viewers see: the chosen thumbnail, else the drawn frame.
            'poster_url' => $video->posterUrl(),
            'auto_poster_url' => $video->autoPosterUrl(),
            'has_thumbnail' => $video->thumbnail_path !== null,
            'duration_seconds' => $video->duration_seconds,
            'width' => $video->width,
            'height' => $video->height,
            'size_bytes' => $video->size_bytes,
            'topics' => $video->topics
                ->sortBy(fn (TutorialVideoTopic $row) => array_search($row->topic, TutorialTopic::values(), true))
                ->map(fn (TutorialVideoTopic $row) => ['key' => $row->topic, 'position' => $row->position])
                ->values(),
            'views_count' => (int) ($video->views_count ?? 0),
            'completions_count' => (int) ($video->completions_count ?? 0),
            'created_by' => $video->creator?->name,
            'created_at' => $video->created_at?->toIso8601String(),
            'updated_at' => $video->updated_at?->toIso8601String(),
            'processed_at' => $video->processed_at?->toIso8601String(),
        ];
    }
}
