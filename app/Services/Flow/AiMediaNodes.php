<?php

namespace App\Services\Flow;

/**
 * The AI media node: have a model make an image, a spoken audio or a video in
 * the middle of a flow, and send it.
 *
 * The request is the author's instruction with the flow's variables filled in,
 * optionally with pictures to work from — the customer's own photo, a template
 * the business uploaded. What comes back is stored as the workspace's file and
 * its address left in `{{ai_media_url}}`, so a later step can send it again.
 *
 * An image node can ask for several pictures at once — one instruction each,
 * everything else shared — and they are all made at the same time rather than
 * one after the other. That is the whole reason for the list: five chained
 * nodes took five generations end to end, one node with five instructions
 * takes as long as the slowest.
 *
 * Behind config ai.media.enabled. The contract with the AI Hub is
 * PINGLY-MEDIA-GENERATION-20261005.md.
 *
 * Mirrored in the builder by lib/aiMediaNodes.ts — the two must agree.
 */
final class AiMediaNodes
{
    public const TYPES = ['image', 'audio', 'video'];

    public const BRANCH_GENERATED = 'generated';

    public const BRANCH_FAILED = 'failed';

    public const BRANCHES = [self::BRANCH_GENERATED, self::BRANCH_FAILED];

    public const IMAGE_SIZES = ['auto', '1024x1024', '1024x1536', '1536x1024'];

    public const IMAGE_QUALITIES = ['auto', 'low', 'medium', 'high'];

    public const VIDEO_SECONDS = [4, 8, 12];

    public const VIDEO_SIZES = ['720x1280', '1280x720'];

    public const MAX_PROMPT_LENGTH = 12000;

    public const MAX_REFERENCES = 3;

    /** Pictures one node may ask for at once. Each is a paid generation. */
    public const MAX_IMAGES = 10;

    /** Why a generation did not produce a file. Codes, translated in the dashboard. */
    public const REASONS = [
        'disabled', 'not_configured', 'no_reference', 'credit_exhausted', 'content_policy',
        'invalid_input', 'unsupported', 'credential_invalid', 'provider_error', 'timeout', 'download_failed',
        'send_failed',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'media_type' => 'image',
            'provider_credential_id' => null,
            'model' => '',
            'prompt' => '',
            'images' => [],
            'use_customer_image' => false,
            'reference_urls' => [],
            'image' => ['size' => 'auto', 'quality' => 'auto'],
            'audio' => ['voice' => ''],
            'video' => ['seconds' => 4, 'size' => '720x1280'],
            'wait_message' => '',
            'caption' => '',
            'send_to_customer' => true,
            'view_once' => false,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function type(array $data): string
    {
        $type = $data['media_type'] ?? 'image';

        return in_array($type, self::TYPES, true) ? $type : 'image';
    }

    /** @param  array<string, mixed>  $data */
    public static function credentialId(array $data): ?int
    {
        $id = (int) ($data['provider_credential_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function isConfigured(array $data): bool
    {
        return self::credentialId($data) !== null && self::items($data) !== [];
    }

    /**
     * What one run of the node makes: one entry per file, in the author's
     * order, each with its own instruction and caption.
     *
     * Only an image node reads `images`. An audio or video node — and an image
     * node written before the list existed — is one entry made of its
     * `prompt` and `caption`.
     *
     * A row with an empty instruction is dropped, not sent: it is a row the
     * author has not finished, and asking the hub for nothing still pays for
     * a file.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{prompt: string, caption: string}>
     */
    public static function items(array $data): array
    {
        $rows = self::type($data) === 'image' && is_array($data['images'] ?? null) && $data['images'] !== []
            ? $data['images']
            : [['prompt' => $data['prompt'] ?? '', 'caption' => $data['caption'] ?? '']];

        $items = [];

        foreach ($rows as $row) {
            $prompt = is_array($row) ? (string) ($row['prompt'] ?? '') : '';

            if (trim($prompt) !== '') {
                $items[] = ['prompt' => $prompt, 'caption' => (string) ($row['caption'] ?? '')];
            }
        }

        return array_slice($items, 0, self::MAX_IMAGES);
    }

    /** Pictures only help an image or a video; speech has nothing to look at. */
    public static function takesReferences(string $type): bool
    {
        return $type !== 'audio';
    }

    /** @param  array<string, mixed>  $data */
    public static function usesCustomerImage(array $data): bool
    {
        return self::takesReferences(self::type($data)) && (bool) ($data['use_customer_image'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function referenceUrls(array $data): array
    {
        if (! self::takesReferences(self::type($data))) {
            return [];
        }

        $urls = array_filter(
            array_map(fn ($url) => is_string($url) ? trim($url) : '', (array) ($data['reference_urls'] ?? [])),
            fn (string $url) => str_starts_with($url, 'https://'),
        );

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_REFERENCES);
    }

    /** @param  array<string, mixed>  $data */
    public static function sendsToCustomer(array $data): bool
    {
        return (bool) ($data['send_to_customer'] ?? true);
    }

    /**
     * The per-type options block of the hub request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function options(array $data, string $audioFormat = 'mp3'): array
    {
        return match (self::type($data)) {
            'image' => [
                'size' => in_array($data['image']['size'] ?? null, self::IMAGE_SIZES, true) ? $data['image']['size'] : 'auto',
                'quality' => in_array($data['image']['quality'] ?? null, self::IMAGE_QUALITIES, true) ? $data['image']['quality'] : 'auto',
                'format' => 'png',
            ],
            'audio' => array_filter([
                'voice' => trim((string) ($data['audio']['voice'] ?? '')) ?: null,
                'format' => $audioFormat,
            ]),
            'video' => [
                'seconds' => in_array((int) ($data['video']['seconds'] ?? 0), self::VIDEO_SECONDS, true) ? (int) $data['video']['seconds'] : 4,
                'size' => in_array($data['video']['size'] ?? null, self::VIDEO_SIZES, true) ? $data['video']['size'] : '720x1280',
            ],
        };
    }

    public static function stateKey(int $nodeId): string
    {
        return "_ai_media_{$nodeId}";
    }

    /**
     * What a parked node is waiting on: its generations in the author's order,
     * how many of them have been delivered, and which files could not be sent.
     *
     * A node parked before several images existed holds a bare generation id.
     *
     * @return array{ids: list<int>, next: int, send_failed: list<int>}
     */
    public static function parked(mixed $value): array
    {
        if (is_numeric($value)) {
            return ['ids' => [(int) $value], 'next' => 0, 'send_failed' => []];
        }

        $value = is_array($value) ? $value : [];

        return [
            'ids' => array_values(array_map('intval', (array) ($value['ids'] ?? []))),
            'next' => max(0, (int) ($value['next'] ?? 0)),
            'send_failed' => array_values(array_map('intval', (array) ($value['send_failed'] ?? []))),
        ];
    }
}
