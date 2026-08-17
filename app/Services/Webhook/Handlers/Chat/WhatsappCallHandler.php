<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Models\Connection;
use App\Services\Conversation\CallLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The `calls` webhook field on a WhatsApp Cloud API number.
 *
 * A Cloud API number cannot be called at all until calling is switched on for
 * it (`POST /{phone-number-id}/settings`, `calling.status = ENABLED`) — the
 * call button simply is not there — so this handler is silent on every number
 * that has not opted in. That is the intended default, not a gap: it is the
 * receiver waiting for the day a tenant turns calling on.
 *
 * Meta multiplexes several fields onto the same WABA webhook, and this one is
 * routed straight here by WhatsAppController rather than through ChatService,
 * for the same reason the coexistence fields are: the chat handler reads
 * `changes[0]` only, so a field sharing an entry with anything else would be
 * dropped depending on where it happened to land.
 */
class WhatsappCallHandler
{
    public const FIELDS = ['calls'];

    /** Entry point from the webhook controller: one change (field + value). */
    public function handleChange(Connection $connection, array $change): void
    {
        $value = $change['value'] ?? [];

        // The profile name rides on the change, not on each call.
        $name = $value['contacts'][0]['profile']['name'] ?? null;
        $waId = $value['contacts'][0]['wa_id'] ?? null;

        foreach ($value['calls'] ?? [] as $call) {
            $this->handleCall($connection, $call, $waId, $name);
        }
    }

    private function handleCall(Connection $connection, array $call, ?string $waId, ?string $name): void
    {
        $callId = $call['id'] ?? null;

        if (! $callId) {
            Log::warning('WhatsappCallHandler: call without an id', [
                'connection_id' => $connection->id,
                'call' => $call,
            ]);

            return;
        }

        $direction = strtolower((string) ($call['direction'] ?? 'user_initiated'));

        // A call this platform placed itself — which it cannot yet do, since
        // answering and dialling both need a media stack we have not built. If
        // one shows up it was dialled through another tool on the same number,
        // and filing it under "incoming call" would be a plain lie. Whoever
        // adds outbound calling here should give it its own states rather than
        // borrow these.
        if ($direction === 'business_initiated') {
            Log::info('WhatsappCallHandler: ignoring business-initiated call', [
                'connection_id' => $connection->id,
                'call_id' => $callId,
            ]);

            return;
        }

        // wa_id is the customer's identity as Meta states it; `from` is the
        // same number but has been seen carrying a leading +, which would fork
        // the contact.
        $phone = $waId ?: ltrim((string) ($call['from'] ?? ''), '+');

        if ($phone === '') {
            Log::warning('WhatsappCallHandler: call without a caller', [
                'connection_id' => $connection->id,
                'call_id' => $callId,
            ]);

            return;
        }

        $event = strtolower((string) ($call['event'] ?? ''));

        match ($event) {
            'connect' => CallLog::record(
                $connection,
                $phone,
                $name,
                $callId,
                CallLog::RINGING,
                $this->timestamp($call['timestamp'] ?? $call['start_time'] ?? null),
                null,
                ['direction' => $direction],
            ),
            'terminate' => $this->terminated($connection, $call, $phone, $name, $callId, $direction),
            default => Log::info('WhatsappCallHandler: unhandled call event', [
                'connection_id' => $connection->id,
                'call_id' => $callId,
                'event' => $call['event'] ?? null,
            ]),
        };
    }

    /**
     * The call is over, and Meta says how it went. `duration` is the honest
     * signal: COMPLETED with nothing on the clock is a call that connected
     * only as far as ringing, which a reader would call missed, not answered.
     */
    private function terminated(
        Connection $connection,
        array $call,
        string $phone,
        ?string $name,
        string $callId,
        string $direction,
    ): void {
        $status = strtoupper((string) ($call['status'] ?? ''));
        $seconds = isset($call['duration']) ? (int) $call['duration'] : null;
        $answered = ($seconds ?? 0) > 0;

        $code = match (true) {
            $status === 'REJECTED' => CallLog::DECLINED,
            $status === 'COMPLETED' && $answered => CallLog::ANSWERED,
            // FAILED, or a status Meta has not documented yet.
            default => CallLog::MISSED,
        };

        CallLog::record(
            $connection,
            $phone,
            $name,
            $callId,
            $code,
            $this->timestamp($call['end_time'] ?? $call['timestamp'] ?? null),
            $code === CallLog::ANSWERED ? $seconds : null,
            array_filter([
                'direction' => $direction,
                'status' => $status ?: null,
            ]),
        );
    }

    /** Meta sends epoch seconds, as a string more often than not. */
    private function timestamp(mixed $value): Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::now();
    }
}
