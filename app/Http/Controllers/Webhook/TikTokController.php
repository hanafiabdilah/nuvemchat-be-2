<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\Webhook\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TikTokController extends Controller
{
    /**
     * Max accepted age of a webhook signature timestamp. TikTok documents 5s,
     * but that assumes zero clock skew; a replayed DM webhook within a few
     * minutes is harmless (message upserts are idempotent by external_id)
     * while a false rejection silently drops messages.
     */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        protected ChatService $chatService,
    ) {
        //
    }

    public function handle(Request $request)
    {
        if (! $this->verifySignature($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        Log::info('TikTok webhook received', $request->all());

        // `user_openid` is the business account the event belongs to — the same
        // id stored as credentials.business_id at OAuth connect.
        $businessId = $request->input('user_openid');

        if (! $businessId) {
            Log::warning('Missing user_openid in TikTok webhook', [
                'payload' => $request->all(),
            ]);

            // Ack anyway: TikTok retries on non-2xx and this payload will never become valid.
            return response()->json(['message' => 'Ignored'], 200);
        }

        $connection = Connection::where('channel', Channel::TikTok)
            ->where('credentials->business_id', (string) $businessId)
            ->first();

        if (! $connection) {
            Log::error('Connection not found for TikTok webhook', [
                'business_id' => $businessId,
            ]);

            return response()->json(['message' => 'Ignored'], 200);
        }

        $this->chatService->handle($connection, $request->all());

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }

    /**
     * TikTok signs each delivery: header `Tiktok-Signature: t=<unix>,s=<hex>`
     * where s = HMAC-SHA256(app_secret, "<t>.<raw body>"). Hard-fails on any
     * mismatch — an unverified payload must never create messages.
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) TikTokConfig::appSecret();

        if ($secret === '') {
            Log::error('TikTok webhook: app secret not configured');
            return false;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('Tiktok-Signature', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $parts[trim((string) $key)] = $value;
        }

        $timestamp = isset($parts['t']) ? (int) $parts['t'] : null;
        $signature = $parts['s'] ?? null;

        if (! $timestamp || ! $signature) {
            Log::warning('TikTok webhook: malformed signature header');
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::error('TikTok webhook: signature verification failed — REJECTING request');
            return false;
        }

        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            Log::warning('TikTok webhook: stale signature timestamp', [
                'timestamp' => $timestamp,
            ]);
            return false;
        }

        return true;
    }
}
