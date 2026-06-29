<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Billing\MercadoPago\MercadoPagoConfig;
use App\Services\Connection\Proxy\ProxyhubConfig;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    /**
     * Return platform settings. Secrets are never sent in full — only a masked
     * preview and a "set" flag so the UI can show whether a value exists.
     */
    public function show()
    {
        $token = ProxyhubConfig::integratorToken();
        $mpAccess = MercadoPagoConfig::accessToken();
        $mpSecret = MercadoPagoConfig::webhookSecret();

        return response()->json([
            'data' => [
                'proxyhub' => [
                    'base_url' => ProxyhubConfig::baseUrl(),
                    'integrator_token_set' => ! empty($token),
                    'integrator_token_preview' => $this->mask($token),
                ],
                'mercadopago' => [
                    // Public key is meant to be exposed (frontend Bricks).
                    'public_key' => MercadoPagoConfig::publicKey(),
                    'back_url' => MercadoPagoConfig::backUrl(),
                    'access_token_set' => ! empty($mpAccess),
                    'access_token_preview' => $this->mask($mpAccess),
                    'webhook_secret_set' => ! empty($mpSecret),
                    'webhook_secret_preview' => $this->mask($mpSecret),
                ],
            ],
        ]);
    }

    /**
     * Update platform settings. The integrator token is only changed when a new
     * non-empty value is supplied, so saving the base URL alone won't wipe it.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'proxyhub' => ['sometimes', 'array'],
            'proxyhub.base_url' => ['required_with:proxyhub', 'url', 'max:255'],
            'proxyhub.integrator_token' => ['nullable', 'string', 'max:255'],

            'mercadopago' => ['sometimes', 'array'],
            'mercadopago.public_key' => ['nullable', 'string', 'max:255'],
            'mercadopago.back_url' => ['nullable', 'url', 'max:255'],
            'mercadopago.access_token' => ['nullable', 'string', 'max:512'],
            'mercadopago.webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->has('proxyhub')) {
            Setting::set(ProxyhubConfig::KEY_BASE_URL, rtrim($validated['proxyhub']['base_url'], '/'));

            if (! empty($validated['proxyhub']['integrator_token'])) {
                Setting::set(ProxyhubConfig::KEY_INTEGRATOR_TOKEN, $validated['proxyhub']['integrator_token']);
            }
        }

        if ($request->has('mercadopago')) {
            $mp = $validated['mercadopago'];

            // Public values: stored as-is (not secret).
            Setting::set(MercadoPagoConfig::KEY_PUBLIC_KEY, $mp['public_key'] ?? null);
            Setting::set(MercadoPagoConfig::KEY_BACK_URL, $mp['back_url'] ?? null);

            // Secrets: only replaced when a new value is supplied.
            if (! empty($mp['access_token'])) {
                Setting::set(MercadoPagoConfig::KEY_ACCESS_TOKEN, $mp['access_token']);
            }
            if (! empty($mp['webhook_secret'])) {
                Setting::set(MercadoPagoConfig::KEY_WEBHOOK_SECRET, $mp['webhook_secret']);
            }
        }

        AuditLog::record('settings.update', 'Updated platform settings');

        return $this->show();
    }

    private function mask(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $len = strlen($value);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', max(4, $len - 4)) . substr($value, -4);
    }
}
