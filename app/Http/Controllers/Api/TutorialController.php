<?php

namespace App\Http\Controllers\Api;

use App\Enums\Tutorial\TutorialTopic;
use App\Http\Controllers\Controller;
use App\Models\TutorialTopicAutoOpen;
use App\Models\TutorialVideo;
use App\Models\TutorialVideoView;
use App\Services\Tutorials\TutorialLibrary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The dashboard's side of the tutorial library: what this person may watch,
 * and what they already have.
 */
class TutorialController extends Controller
{
    public function index(Request $request, TutorialLibrary $library): JsonResponse
    {
        $request->validate(['locale' => ['nullable', 'string', 'max:10']]);

        return response()->json($library->forUser($request->user(), $request->query('locale')));
    }

    /**
     * Played, and possibly to the end. Idempotent: the first play stamps
     * `first_viewed_at`, the first finish stamps `completed_at`, and nothing
     * is ever un-stamped.
     */
    public function view(Request $request, TutorialVideo $video): JsonResponse
    {
        $data = $request->validate(['completed' => ['sometimes', 'boolean']]);

        abort_unless($video->isPlayable(), 404);

        $userId = $request->user()->id;
        $view = TutorialVideoView::query()->where('user_id', $userId)->where('tutorial_video_id', $video->id)->first();

        if (! $view) {
            try {
                $view = TutorialVideoView::create([
                    'user_id' => $userId,
                    'tutorial_video_id' => $video->id,
                    'first_viewed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two tabs, one play: the other request wrote the row.
                $view = TutorialVideoView::query()->where('user_id', $userId)->where('tutorial_video_id', $video->id)->firstOrFail();
            }
        }

        if (($data['completed'] ?? false) && $view->completed_at === null) {
            $view->update(['completed_at' => now()]);
        }

        return response()->json([
            'viewed' => true,
            'completed' => $view->completed_at !== null,
        ]);
    }

    /**
     * Claim a topic's one automatic opening. `opened: true` only for the
     * request that wrote the row, so two tabs on the same page open the video
     * once between them, and never again after that.
     */
    public function autoOpen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(TutorialTopic::values())],
        ]);

        try {
            $row = TutorialTopicAutoOpen::firstOrCreate([
                'user_id' => $request->user()->id,
                'topic' => $data['topic'],
            ]);
            $opened = $row->wasRecentlyCreated;
        } catch (UniqueConstraintViolationException) {
            $opened = false;
        }

        return response()->json(['opened' => $opened]);
    }
}
