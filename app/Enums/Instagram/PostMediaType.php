<?php

namespace App\Enums\Instagram;

/**
 * The shapes Instagram will publish for us.
 *
 * These map to the `media_type` parameter of the container endpoint, with one
 * exception worth remembering: a single feed image takes NO media_type at all
 * (Meta defaults to IMAGE and rejects an explicit one), which is why
 * containerMediaType() can return null.
 */
enum PostMediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Reels = 'reels';
    case Carousel = 'carousel';
    case Stories = 'stories';

    /** What to send Meta as `media_type`, or null when it must be omitted. */
    public function containerMediaType(): ?string
    {
        return match ($this) {
            self::Image => null,
            self::Video => 'VIDEO',
            self::Reels => 'REELS',
            self::Carousel => 'CAROUSEL',
            self::Stories => 'STORIES',
        };
    }

    public function isCarousel(): bool
    {
        return $this === self::Carousel;
    }

    /** Stories carry no caption — Meta silently drops one if sent. */
    public function supportsCaption(): bool
    {
        return $this !== self::Stories;
    }

    /**
     * Whether Meta builds this one asynchronously. Images are ready the moment
     * the container call returns; anything with a video track has to be
     * transcoded first, so the publisher polls status_code before publishing.
     */
    public function isAsync(): bool
    {
        return $this !== self::Image;
    }

    /** How many media items this type accepts. */
    public function itemRange(): array
    {
        return $this->isCarousel() ? [2, 10] : [1, 1];
    }
}
