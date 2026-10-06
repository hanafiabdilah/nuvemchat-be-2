<?php

namespace App\Services\Sales;

use App\Enums\Message\SenderType;
use App\Models\AdReferral;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Which ad a customer came from.
 *
 * A click-to-WhatsApp ad opens the chat with a prepared message, and that one
 * message carries the ad's identity — Meta's ad id among it. Nothing later in
 * the thread repeats it, so it is read off the message as it is stored and
 * kept where a report can count it.
 *
 * Two dialects, one fact: the Cloud API calls it `referral`, the whatsmeow
 * events put it in the message's `contextInfo.externalAdReply`.
 *
 * One referral per conversation, the first: a customer who taps a second ad
 * while already talking has not started over.
 */
final class AdReferrals
{
    /** How far back a returning customer's ad still gets credit for a sale. */
    public const ATTRIBUTION_DAYS = 30;

    public static function capture(Message $message): ?AdReferral
    {
        if ($message->sender_type !== SenderType::Incoming || ! is_array($message->meta)) {
            return null;
        }

        $ad = self::read($message->meta);

        if ($ad === null) {
            return null;
        }

        try {
            $conversation = $message->conversation()->with('connection')->first();
            $connection = $conversation?->getRelationValue('connection');

            if (! $conversation || ! $connection) {
                return null;
            }

            return AdReferral::firstOrCreate(
                ['conversation_id' => $conversation->id],
                $ad + [
                    'tenant_id' => $connection->tenant_id,
                    'connection_id' => $connection->id,
                    'contact_id' => $conversation->contact_id,
                    'platform' => 'meta',
                    'referred_at' => $message->created_at ?? now(),
                ],
            );
        } catch (\Throwable $e) {
            // A report losing one attribution is not a reason for a customer's
            // message to fail to arrive.
            Log::warning('AdReferrals: could not record the ad a conversation came from', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The ad to credit for a sale in this conversation: its own, or failing
     * that the most recent one the same customer arrived through.
     */
    public static function adIdFor(?Conversation $conversation): ?string
    {
        if (! $conversation) {
            return null;
        }

        $own = AdReferral::where('conversation_id', $conversation->id)->value('ad_id');

        if ($own !== null || ! $conversation->contact_id) {
            return $own;
        }

        return AdReferral::where('contact_id', $conversation->contact_id)
            ->where('referred_at', '>=', now()->subDays(self::ATTRIBUTION_DAYS))
            ->orderByDesc('referred_at')
            ->value('ad_id');
    }

    /**
     * @param  array<string, mixed>  $meta  the stored webhook payload
     * @return array{ad_id: ?string, source_type: ?string, source_url: ?string, title: ?string, ctwa_clid: ?string}|null
     */
    public static function read(array $meta): ?array
    {
        // WhatsApp Cloud API.
        $referral = $meta['changes'][0]['value']['messages'][0]['referral'] ?? null;

        if (is_array($referral) && $referral !== []) {
            return self::shape(
                $referral['source_id'] ?? null,
                $referral['source_type'] ?? null,
                $referral['source_url'] ?? null,
                $referral['headline'] ?? null,
                $referral['ctwa_clid'] ?? null,
            );
        }

        // whatsmeow (API Way): whichever node the message is, its contextInfo.
        foreach ((array) ($meta['Message'] ?? []) as $node) {
            $context = is_array($node) ? ($node['contextInfo'] ?? null) : null;
            $ad = is_array($context) ? ($context['externalAdReply'] ?? null) : null;

            if (is_array($ad) && (($ad['sourceID'] ?? null) || ($ad['ctwaClid'] ?? null) || ($context['conversionSource'] ?? null))) {
                return self::shape(
                    $ad['sourceID'] ?? null,
                    $ad['sourceType'] ?? null,
                    $ad['sourceURL'] ?? null,
                    $ad['title'] ?? null,
                    $ad['ctwaClid'] ?? null,
                );
            }
        }

        return null;
    }

    /** @return array{ad_id: ?string, source_type: ?string, source_url: ?string, title: ?string, ctwa_clid: ?string} */
    private static function shape(mixed $id, mixed $type, mixed $url, mixed $title, mixed $clid): array
    {
        $text = fn (mixed $value, int $max) => is_scalar($value) && trim((string) $value) !== ''
            ? Str::limit(trim((string) $value), $max, '')
            : null;

        return [
            'ad_id' => $text($id, 64),
            'source_type' => $text($type, 20),
            'source_url' => $text($url, 500),
            'title' => $text($title, 255),
            'ctwa_clid' => $text($clid, 255),
        ];
    }
}
