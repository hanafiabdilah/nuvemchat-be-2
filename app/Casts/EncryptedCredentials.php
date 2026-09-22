<?php

namespace App\Casts;

use App\Services\Connection\ConnectionCredentials;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `connections.credentials`: still JSON the database can query into, with the
 * secret values inside it encrypted.
 *
 * A thin shell on purpose — the rule it applies, and the reasons for it, live
 * in App\Services\Connection\ConnectionCredentials. Read that before changing
 * anything here.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
class EncryptedCredentials implements CastsAttributes
{
    /** @return array<string, mixed>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return ConnectionCredentials::reveal($decoded);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $value = json_decode((string) $value, true);

            if (! is_array($value)) {
                return null;
            }
        }

        return json_encode(ConnectionCredentials::protect($value));
    }
}
