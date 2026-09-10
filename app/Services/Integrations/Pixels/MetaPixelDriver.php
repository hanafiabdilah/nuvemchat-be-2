<?php

namespace App\Services\Integrations\Pixels;

use App\Models\Integration;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Integrations\Concerns\CallsProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

    public function verify(): array
    {
        $json = $this->call(fn (PendingRequest $http) => $http->get('/'.rawurlencode($this->pixelId()), [
            'fields' => 'id,name',
            'access_token' => $this->integration->credential('access_token'),
        ]), 'verify');

        return array_filter([
            'account_name' => $json['name'] ?? null,
            'environment' => $this->integration->setting('test_event_code') ? 'test' : 'production',
        ]);
    }

    public function send(PixelEvent $event): void
    {
        $payload = [
            'data' => [$this->eventData($event)],
            'access_token' => $this->integration->credential('access_token'),
        ];

        $testCode = trim((string) $this->integration->setting('test_event_code', ''));
        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        $this->call(
            fn (PendingRequest $http) => $http->post('/'.rawurlencode($this->pixelId()).'/events', $payload),
            'send_event',
        );
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
