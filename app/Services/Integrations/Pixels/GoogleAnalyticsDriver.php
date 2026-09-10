<?php

namespace App\Services\Integrations\Pixels;

use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use App\Support\Errors\UpstreamError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GA4's Measurement Protocol: events sent server-side to a property.
 *
 * ⚠️ The collect endpoint answers 2xx to almost anything, a wrong secret
 * included — Google chose silence over telling senders what is wrong. So a
 * successful send proves nothing, and "test connection" uses the debug
 * endpoint instead, which at least validates the payload shape. The page says
 * that honestly rather than printing a green tick it cannot back up.
 *
 * `client_id` is required and normally comes from the browser cookie. A
 * conversation has no cookie, so it is derived from the contact — stable, so
 * the same person is one user across events, and opaque, so no id of ours
 * appears in someone else's reports.
 */
class GoogleAnalyticsDriver implements PixelDriver
{
    use CallsProvider;

    public const BASE_URL = 'https://www.google-analytics.com';

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $message = $response->json('error.message') ?? $response->json('validationMessages.0.description');

        return [is_string($message) ? $message : null, null];
    }

    public function verify(): array
    {
        $sample = new PixelEvent(
            event: 'lead',
            customName: null,
            value: null,
            currency: 'BRL',
            parameters: [],
            eventId: 'verify',
            eventTime: time(),
            identity: ['external_id' => 'verify'],
        );

        $json = $this->call(
            fn (PendingRequest $http) => $http->post('/debug/mp/collect?'.$this->query(), $this->payload($sample)),
            'verify',
        );

        $messages = (array) ($json['validationMessages'] ?? []);

        if ($messages !== []) {
            $first = (array) $messages[0];

            throw UpstreamError::exception(
                $this->integration->provider->upstream(),
                ($first['fieldPath'] ?? '').' '.($first['description'] ?? 'validation failed'),
                isset($first['validationCode']) ? (string) $first['validationCode'] : null,
                422,
                ['integration_id' => $this->integration->id, 'action' => 'verify'],
            );
        }

        return ['account_name' => (string) $this->integration->setting('measurement_id')];
    }

    public function send(PixelEvent $event): void
    {
        $this->call(
            fn (PendingRequest $http) => $http->post('/mp/collect?'.$this->query(), $this->payload($event)),
            'send_event',
        );
    }

    private function query(): string
    {
        return http_build_query([
            'measurement_id' => (string) $this->integration->setting('measurement_id'),
            'api_secret' => (string) $this->integration->credential('api_secret'),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(PixelEvent $event): array
    {
        $params = $event->parameters;

        if ($event->value !== null) {
            $params['value'] = $event->value;
            $params['currency'] = $event->currency;
        }

        // Without it GA4 does not count the user as active, and the event
        // disappears from most of the reports people actually open.
        $params['engagement_time_msec'] = 1;

        $body = [
            'client_id' => $this->clientId($event),
            'timestamp_micros' => $event->eventTime * 1_000_000,
            'events' => [[
                'name' => PixelEvents::googleName($event),
                'params' => $params,
            ]],
        ];

        $userData = array_filter([
            'sha256_email_address' => self::hash($event->identity['email'] ?? null),
            // Google's phone normalisation: E.164, plus sign included.
            'sha256_phone_number' => isset($event->identity['phone'])
                ? self::hash('+'.preg_replace('/\D+/', '', $event->identity['phone']))
                : null,
        ]);

        if ($userData !== []) {
            $body['user_data'] = $userData;
        }

        return $body;
    }

    private function clientId(PixelEvent $event): string
    {
        $seed = hash('sha256', (string) ($event->identity['external_id'] ?? $event->eventId));

        return hexdec(substr($seed, 0, 8)).'.'.hexdec(substr($seed, 8, 8));
    }

    /** @return list<string>|null */
    private static function hash(?string $value): ?array
    {
        $normalized = trim(mb_strtolower((string) $value));

        return $normalized === '' || $normalized === '+' ? null : [hash('sha256', $normalized)];
    }
}
