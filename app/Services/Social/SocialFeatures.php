<?php

namespace App\Services\Social;

use Illuminate\Http\JsonResponse;

/**
 * Platform switches for the social features waiting on a platform's app
 * review (config/social.php). One place answers "is this on", so the routes,
 * the comment endpoints, the TikTok channel and the dashboard flags cannot
 * disagree about it.
 *
 * A switch only ever takes away: it sits on top of the plan feature and the
 * permission, never instead of them.
 */
final class SocialFeatures
{
    public const TIKTOK = 'tiktok';

    public const YOUTUBE = 'youtube';

    public const INSTAGRAM_COMMENTS = 'instagram_comments';

    public const ALL = [self::TIKTOK, self::YOUTUBE, self::INSTAGRAM_COMMENTS];

    public static function enabled(string $feature): bool
    {
        return (bool) config("social.{$feature}", false);
    }

    /** The switch behind a network's comments (CommentMarks::PLATFORMS). */
    public static function forCommentPlatform(string $platform): string
    {
        return match ($platform) {
            'instagram' => self::INSTAGRAM_COMMENTS,
            'tiktok' => self::TIKTOK,
            'youtube' => self::YOUTUBE,
        };
    }

    /** What the dashboard reads under `platform` in GET /api/user. */
    public static function flags(): array
    {
        return array_combine(self::ALL, array_map(fn (string $feature) => self::enabled($feature), self::ALL));
    }

    public static function refusal(string $feature): JsonResponse
    {
        return response()->json([
            'message' => 'This feature is not available yet.',
            'code' => 'feature_unavailable',
            'feature' => $feature,
        ], 403);
    }
}
