<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Social features still under each platform's app review
    |--------------------------------------------------------------------------
    |
    | TikTok (the DM channel and post/comment management), YouTube management,
    | and Instagram comment moderation (Comments inbox, Comment → Direct) need
    | permissions TikTok, Google and Meta have not granted yet (Nov 2026).
    |
    | Off: the dashboard hides them and the API refuses them with 403
    | `feature_unavailable` — plan features and permissions are not touched.
    | Nothing stored is deleted, so switching one on brings it back exactly as
    | it was, without a deploy (`up -d --force-recreate` to reread .env).
    |
    | Instagram posts are not here: publishing stays on.
    */

    'tiktok' => (bool) env('SOCIAL_TIKTOK_ENABLED', false),

    'youtube' => (bool) env('SOCIAL_YOUTUBE_ENABLED', false),

    'instagram_comments' => (bool) env('SOCIAL_INSTAGRAM_COMMENTS_ENABLED', false),
];
