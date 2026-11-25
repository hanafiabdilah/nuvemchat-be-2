<?php

namespace App\Services\Tutorials;

use App\Enums\Tutorial\TutorialTopic;
use App\Models\TutorialTopicAutoOpen;
use App\Models\TutorialVideo;
use App\Models\TutorialVideoTopic;
use App\Models\TutorialVideoView;
use App\Models\User;

/**
 * The tutorial library as one person sees it.
 *
 * Small enough to send whole: the dashboard loads it once after sign-in and
 * every page asks it "anything for my topic?" without a request of its own.
 *
 * Language is chosen per topic: a topic with videos in the reader's language
 * shows those; one without shows the fallback language's (config
 * `tutorials.fallback_locale`) — a video in Portuguese beats a page with no
 * video for somebody reading in English. A video can therefore appear under
 * some of its topics and not others.
 */
final class TutorialLibrary
{
    /**
     * @return array{videos: list<array<string, mixed>>, topics: list<array{key: string, label: string, area: string, parent: ?string}>, areas: list<array{key: string, label: string}>, auto_opened: list<string>}
     */
    public function forUser(User $user, ?string $locale): array
    {
        $locales = array_keys((array) config('markets.locales', []));
        $fallback = (string) config('tutorials.fallback_locale', 'pt_BR');
        $locale = in_array($locale, $locales, true) ? $locale : $fallback;

        $topics = TutorialTopic::availableFor($user);
        $topicKeys = array_map(fn (TutorialTopic $topic) => $topic->value, $topics);

        $rows = TutorialVideoTopic::query()
            ->whereIn('topic', $topicKeys)
            ->whereHas('video', fn ($query) => $query->playable())
            ->with('video')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('topic');

        /** @var array<int, array{video: TutorialVideo, topics: list<array{key: string, position: int}>}> $chosen */
        $chosen = [];
        $withVideos = [];

        foreach ($topics as $topic) {
            $placements = $rows->get($topic->value);
            if (! $placements) {
                continue;
            }

            $inLocale = $placements->filter(fn (TutorialVideoTopic $row) => $row->video->locale === $locale);
            $picked = $inLocale->isNotEmpty()
                ? $inLocale
                : $placements->filter(fn (TutorialVideoTopic $row) => $row->video->locale === $fallback);

            foreach ($picked->values() as $index => $row) {
                $chosen[$row->tutorial_video_id] ??= ['video' => $row->video, 'topics' => []];
                $chosen[$row->tutorial_video_id]['topics'][] = ['key' => $topic->value, 'position' => $index];
            }

            if ($picked->isNotEmpty()) {
                $withVideos[] = $topic;
            }
        }

        $views = TutorialVideoView::query()
            ->where('user_id', $user->id)
            ->whereIn('tutorial_video_id', array_keys($chosen))
            ->get()
            ->keyBy('tutorial_video_id');

        $videos = [];
        foreach ($chosen as $id => $entry) {
            $video = $entry['video'];
            $view = $views->get($id);

            $videos[] = [
                'id' => $video->id,
                'title' => $video->title,
                'description' => $video->description,
                'locale' => $video->locale,
                'video_url' => $video->videoUrl(),
                'poster_url' => $video->posterUrl(),
                'duration_seconds' => $video->duration_seconds,
                'width' => $video->width,
                'height' => $video->height,
                'auto_open' => $video->auto_open,
                'topics' => $entry['topics'],
                'viewed' => $view !== null,
                'completed' => $view?->completed_at !== null,
                'created_at' => $video->created_at?->toIso8601String(),
            ];
        }

        $areas = [];
        foreach (TutorialTopic::AREAS as $key => $label) {
            $areas[] = ['key' => $key, 'label' => $label];
        }

        return [
            'videos' => $videos,
            'topics' => array_map(fn (TutorialTopic $topic) => $topic->describe(), $withVideos),
            'areas' => $areas,
            'auto_opened' => TutorialTopicAutoOpen::query()
                ->where('user_id', $user->id)
                ->pluck('topic')
                ->values()
                ->all(),
        ];
    }
}
