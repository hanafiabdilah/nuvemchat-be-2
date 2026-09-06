<?php

namespace Tests\Support;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for the API Way connect/switch suites: a tenant, a purchased
 * instance, an unlinked connection, and the two remote surfaces a link touches
 * (partner console for the token, core for webhooks/QR/status).
 */
class ApiwayFixtures
{
    public static function tenant(): Tenant
    {
        $user = User::factory()->create(['email' => 'cx-' . uniqid() . '@example.test']);
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        return $tenant->fresh();
    }

    public static function ownedInstance(Tenant $tenant, array $attributes = []): ApiwayInstance
    {
        $row = ApiwaySubscription::create([
            'tenant_id' => $tenant->id,
            'external_ref' => 'pingly-apw-' . uniqid(),
            'provider_subscription_id' => random_int(1000, 999999),
            'source' => ApiwaySubscriptionSource::Unit,
            'cycle' => 'mensal',
            'quantity' => 1,
            'location_code' => 'br',
            'status' => ApiwaySubscriptionStatus::Active,
            'expires_at' => now()->addDays(30),
        ]);

        return ApiwayInstance::create(array_merge([
            'tenant_id' => $tenant->id,
            'apiway_subscription_id' => $row->id,
            'provider_instance_id' => 'uuid-' . uniqid(),
            'name' => 'Instancia',
            'status' => 'aguardando_qr',
        ], $attributes));
    }

    public static function connection(Tenant $tenant): Connection
    {
        return Connection::create([
            'tenant_id' => $tenant->id,
            'channel' => Channel::WhatsappApiway,
            'name' => 'API ' . uniqid(),
            'status' => ConnectionStatus::Inactive,
        ]);
    }

    /** A connection already linked to $instance, as connect() would leave it. */
    public static function linkedConnection(ApiwayInstance $instance, array $extraCredentials = []): Connection
    {
        $connection = self::connection($instance->tenant);
        $instance->update(['connection_id' => $connection->id]);
        $connection->update([
            'status' => ConnectionStatus::Active,
            'credentials' => array_merge([
                'instance_id' => $instance->provider_instance_id,
                'token' => 'old-token',
                'is_managed' => true,
                'apiway_instance_id' => $instance->id,
                'qr_code' => 'data:image/png;base64,OLD',
                'phone_number' => '5511999999999',
            ], $extraCredentials),
        ]);

        return $connection->fresh();
    }

    public static function fakeLinkSurface(string $instanceUuid): void
    {
        Http::fake([
            "portal.proxybr.com.br/api/partner/v1/apiway/instances/{$instanceUuid}/token" => Http::response([
                'data' => ['token' => 'instance-token-1', 'masked' => 'inst***1'],
            ]),
            // Webhooks register straight on the core (per-event endpoints), not
            // through the partner console.
            'whats-api.ipbr.pro/v1/instance/update-webhook-*' => Http::response(['success' => true]),
            'whats-api.ipbr.pro/v1/instance/qr-code*' => Http::response([
                'success' => true, 'data' => ['qrcode' => 'data:image/png;base64,QR'],
            ]),
            'whats-api.ipbr.pro/v1/instance/status-instance*' => Http::response([
                'success' => true, 'data' => ['connected' => false, 'loggedIn' => false],
            ]),
        ]);
    }
}
