<?php

namespace App\Services\Integrations\Pixels;

/**
 * One conversion, resolved at the moment the flow reached the pixel node.
 *
 * Built with the variables interpolated *then*, not when the queued job runs:
 * a flow keeps moving while the event waits for a worker, and "the value the
 * customer chose" must be the value they had chosen at this step, not whatever
 * a later node overwrote it with.
 *
 * `identity` holds the raw contact details (phone, e-mail, name). Hashing is
 * each driver's job, because each wants a different normalisation — Meta
 * hashes the phone as bare digits, Google as E.164 with the plus.
 */
final class PixelEvent
{
    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $identity  external_id, phone, email, first_name, last_name
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $customName,
        public readonly ?float $value,
        public readonly string $currency,
        public readonly array $parameters,
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly array $identity,
    ) {}

    /** @return array<string, mixed> — the shape a queued job carries */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'custom_name' => $this->customName,
            'value' => $this->value,
            'currency' => $this->currency,
            'parameters' => $this->parameters,
            'event_id' => $this->eventId,
            'event_time' => $this->eventTime,
            'identity' => $this->identity,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            event: (string) ($data['event'] ?? 'lead'),
            customName: isset($data['custom_name']) ? (string) $data['custom_name'] : null,
            value: isset($data['value']) ? (float) $data['value'] : null,
            currency: (string) ($data['currency'] ?? 'BRL'),
            parameters: (array) ($data['parameters'] ?? []),
            eventId: (string) ($data['event_id'] ?? ''),
            eventTime: (int) ($data['event_time'] ?? time()),
            identity: (array) ($data['identity'] ?? []),
        );
    }
}
