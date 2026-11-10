<?php

namespace App\Services\Integrations\Pixels;

use App\Models\Integration;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Integrations\Concerns\CallsProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta's Conversions API: the server-side half of a pixel.
 *
 * A conversation has no browser, so there is no pixel script to fire — this is
 * the only way a conversion that happened in WhatsApp reaches the ad account
 * that paid for the click. `action_source: chat` is Meta's own value for
 * exactly that ("the conversion happened in a messaging app").
 *
 * Customer details are hashed (SHA-256 of the normalised value) before they
 * leave, as Meta requires; the raw values never go over the wire. `event_id`
 * is ours and unique per node visit, so a job retried after a timeout is
 * de-duplicated by Meta instead of counted twice.
 */
class MetaPixelDriver implements PixelDriver
{
    use CallsProvider;

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.FacebookConfig::graphVersion())
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $message = $response->json('error.message');
        $code = $response->json('error.code');

        return [is_string($message) ? $message : null, $code !== null ? (string) $code : null];
    }

    /**
     * Whether this token can report to this pixel — proven without reporting
     * anything.
     *
     * Reading the pixel answers that for a system-user token, and gives us its
     * name. It does not for the token most customers have: the one Events
     * Manager generates under Conversions API (and the one the form tells them
     * to get) can send events but not read the pixel, so the read comes back
     * HTTP 400 "(#100) Missing Permission". Refusing that refused every Meta
     * pixel anyone tried to connect.
     *
     * So a read refused only for permission is followed by a write Meta is
     * certain to throw away: one event dated ACCESS_CHECK_AGE_DAYS ago. Meta
     * documents that an event older than seven days fails the whole request
     * and that no event in it is processed — and it checks the token's access
     * to the pixel first, so a token that cannot write to it is still told so
     * (subcode 33), while one that can is refused for the date alone.
     */
    public function verify(): array
    {
        $read = $this->attempt(fn (PendingRequest $http) => $http->get('/'.rawurlencode($this->pixelId()), [
            'fields' => 'id,name',
            'access_token' => $this->integration->credential('access_token'),
        ]), 'verify');

        if ($read->successful()) {
            return array_filter([
                'account_name' => $read->json('name'),
                'environment' => $this->environment(),
            ]);
        }

        if (! self::isReadOnlyRefusal($read)) {
            $this->refuse($read, 'verify');
        }

        $write = $this->attempt(fn (PendingRequest $http) => $http->post(
            '/'.rawurlencode($this->pixelId()).'/events',
            $this->withTestCode([
                'data' => [$this->accessCheckEvent()],
                'access_token' => $this->integration->credential('access_token'),
            ]),
        ), 'verify_send');

        if ($write->failed() && ! self::isEventOnlyRefusal($write)) {
            $this->refuse($write, 'verify_send');
        }

        // Kept in the log on purpose: the refusal we count on is Meta's
        // behaviour, not ours, and this line is how we find out it changed.
        Log::info('Meta pixel verified with a send-only token', [
            'integration_id' => $this->integration->id,
            'tenant_id' => $this->integration->tenant_id,
            'upstream_status' => $write->status(),
            'upstream_subcode' => $write->json('error.error_subcode'),
            'upstream_message' => $write->json('error.message'),
        ]);

        return array_filter([
            'access' => 'send_only',
            'environment' => $this->environment(),
        ]);
    }

    public function send(PixelEvent $event): void
    {
        $payload = $this->withTestCode([
            'data' => [$this->eventData($event)],
            'access_token' => $this->integration->credential('access_token'),
        ]);

        $this->call(
            fn (PendingRequest $http) => $http->post('/'.rawurlencode($this->pixelId()).'/events', $payload),
            'send_event',
        );
    }

    /**
     * Comfortably past the seven days Meta allows for a chat event, and past
     * the 62 it allows for a store one, so no source rule can let it through.
     */
    private const ACCESS_CHECK_AGE_DAYS = 90;

    /** @return array<string, mixed> */
    private function accessCheckEvent(): array
    {
        return [
            'event_name' => 'PinglyAccessCheck',
            'event_time' => now()->subDays(self::ACCESS_CHECK_AGE_DAYS)->getTimestamp(),
            'action_source' => 'chat',
            'user_data' => ['external_id' => [hash('sha256', 'pingly-access-check')]],
        ];
    }

    /**
     * The pixel exists and the token is valid, but it may not read the pixel:
     * what Meta answers an Events Manager token. A pixel that does not exist,
     * or one the token cannot reach at all, carries subcode 33 instead.
     */
    private static function isReadOnlyRefusal(Response $response): bool
    {
        return $response->status() === 400
            && (string) $response->json('error.code') === '100'
            && $response->json('error.error_subcode') === null
            && str_contains(mb_strtolower((string) $response->json('error.message')), 'missing permission');
    }

    /**
     * Meta looked at the event and refused it — only possible once it has
     * accepted the token's access to the pixel. Anything that names the pixel
     * or the permission instead is a real refusal.
     */
    private static function isEventOnlyRefusal(Response $response): bool
    {
        $message = mb_strtolower((string) $response->json('error.message'));

        return $response->status() === 400
            && (string) $response->json('error.code') === '100'
            && (string) $response->json('error.error_subcode') !== '33'
            && ! str_contains($message, 'does not exist')
            && ! str_contains($message, 'missing permission');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withTestCode(array $payload): array
    {
        $testCode = trim((string) $this->integration->setting('test_event_code', ''));

        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        return $payload;
    }

    private function environment(): string
    {
        return $this->integration->setting('test_event_code') ? 'test' : 'production';
    }

    private function pixelId(): string
    {
        return (string) $this->integration->setting('pixel_id', '');
    }

    /** @return array<string, mixed> */
    private function eventData(PixelEvent $event): array
    {
        $customData = $event->parameters;

        if ($event->value !== null) {
            $customData['value'] = $event->value;
            $customData['currency'] = $event->currency;
        } elseif ($event->event === 'purchase') {
            // Meta refuses a Purchase without both. An author who wired the
            // node without a value still gets the conversion counted.
            $customData['value'] = 0;
            $customData['currency'] = $event->currency;
        }

        return array_filter([
            'event_name' => PixelEvents::metaName($event),
            'event_time' => $event->eventTime,
            'event_id' => $event->eventId,
            'action_source' => 'chat',
            'user_data' => $this->userData($event->identity),
            'custom_data' => $customData !== [] ? $customData : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, string>  $identity
     * @return array<string, list<string>>
     */
    private function userData(array $identity): array
    {
        return array_filter([
            'external_id' => self::hash($identity['external_id'] ?? null),
            // Meta's phone normalisation: digits only, country code included.
            'ph' => self::hash(isset($identity['phone']) ? preg_replace('/\D+/', '', $identity['phone']) : null),
            'em' => self::hash($identity['email'] ?? null),
            'fn' => self::hash($identity['first_name'] ?? null),
            'ln' => self::hash($identity['last_name'] ?? null),
        ]);
    }

    /** @return list<string>|null */
    private static function hash(?string $value): ?array
    {
        $normalized = trim(mb_strtolower((string) $value));

        return $normalized === '' ? null : [hash('sha256', $normalized)];
    }
}
