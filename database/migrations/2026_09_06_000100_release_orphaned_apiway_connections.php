<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\ApiwayInstance;
use App\Models\Connection;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use Illuminate\Database\Migrations\Migration;

/**
 * Repair API Way connections still holding credentials for an instance that
 * stopped serving them.
 *
 * Cancelling or expiring a subscription used to unlink the instance row and
 * mark the connection inactive while leaving `instance_id`, the API token and
 * the last QR code on the connection. The result is a connect screen offering
 * a QR code that can never pair — every attempt dies inside the core with
 * "not connected" — and no way back, because linking an instance is only
 * reachable while none is stored.
 *
 * releaseInstances() now calls releaseCredentials() at the moment the
 * subscription ends. This is the same repair applied once to rows that were
 * cut loose before that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Connection::query()
            ->where('channel', Channel::WhatsappApiway->value)
            ->cursor()
            ->each(function (Connection $connection) {
                $credentials = $connection->credentials ?? [];
                $instanceId = $credentials['apiway_instance_id'] ?? null;

                // Never linked, or already released: nothing to repair.
                if (! isset($credentials['instance_id']) && ! $instanceId) {
                    return;
                }

                $instance = $instanceId
                    ? ApiwayInstance::where('id', $instanceId)
                        ->where('tenant_id', $connection->tenant_id)
                        ->first()
                    : null;

                // Still genuinely linked and payable — leave it alone.
                if ($instance
                    && (int) $instance->connection_id === (int) $connection->id
                    && $instance->isUsable()) {
                    return;
                }

                // Live instance that merely lost its back-reference: restore
                // the link instead of tearing the connection down. Releasing a
                // working inbox because of a bookkeeping gap would take a
                // channel offline to fix a column.
                if ($instance && $instance->isUsable() && $instance->connection_id === null) {
                    $instance->update(['connection_id' => $connection->id]);

                    return;
                }

                $reason = match (true) {
                    ! $instance => 'instance_missing',
                    ! $instance->isUsable() => 'subscription_ended',
                    default => 'instance_taken',
                };

                WhatsappApiwayChannel::releaseCredentials($connection, $reason);
                $connection->update(['status' => ConnectionStatus::Inactive]);
            });
    }

    public function down(): void
    {
        // The released credentials pointed at instances that no longer answer;
        // putting them back would only restore a QR code that cannot pair.
    }
};
