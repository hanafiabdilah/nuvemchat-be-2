<?php

namespace App\Jobs;

use App\Enums\Integration\IntegrationCategory;
use App\Exceptions\UpstreamServiceException;
use App\Models\Integration;
use App\Services\Integrations\IntegrationDrivers;
use App\Services\Integrations\Pixels\PixelEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One conversion, delivered to one pixel.
 *
 * Queued so the flow never waits on an ad platform. Retried only for outages:
 * a refused token, an unknown pixel or a rejected event name will be refused
 * the same way on the next attempt, so retrying would only delay the error
 * reaching the Integrations page, where it is recorded for somebody to fix.
 * The event id is ours and stable across retries, so a retry after a timeout
 * the provider had already accepted is de-duplicated there, not counted twice.
 */
class SendPixelEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $event  PixelEvent::toArray()
     */
    public function __construct(
        public int $integrationId,
        public array $event,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $integration = Integration::find($this->integrationId);

        // Disabled or deleted after the flow queued this: the workspace turned
        // it off, and that includes events already on their way.
        if ($integration === null || ! $integration->enabled || $integration->category() !== IntegrationCategory::Pixel) {
            return;
        }

        try {
            IntegrationDrivers::pixel($integration)->send(PixelEvent::fromArray($this->event));
        } catch (UpstreamServiceException $e) {
            $integration->recordError($e->getMessage());

            if ($e->httpStatus >= 500 || $e->httpStatus === 429) {
                throw $e;
            }

            return;
        }

        $integration->recordUse();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('SendPixelEvent: event dropped after retries', [
            'integration_id' => $this->integrationId,
            'event' => $this->event['event'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
