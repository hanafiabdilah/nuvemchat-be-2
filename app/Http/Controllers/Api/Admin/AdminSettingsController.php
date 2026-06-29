<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
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

        return response()->json([
            'data' => [
                'proxyhub' => [
                    'base_url' => ProxyhubConfig::baseUrl(),
                    'integrator_token_set' => ! empty($token),
                    'integrator_token_preview' => $this->mask($token),
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
            'proxyhub' => ['required', 'array'],
            'proxyhub.base_url' => ['required', 'url', 'max:255'],
            'proxyhub.integrator_token' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::set(ProxyhubConfig::KEY_BASE_URL, rtrim($validated['proxyhub']['base_url'], '/'));

        $token = $validated['proxyhub']['integrator_token'] ?? null;
        if (! empty($token)) {
            Setting::set(ProxyhubConfig::KEY_INTEGRATOR_TOKEN, $token);
        }

        AuditLog::record('settings.update', 'Updated platform settings (proxyhub)');

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
