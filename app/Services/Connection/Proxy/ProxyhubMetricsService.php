<?php

namespace App\Services\Connection\Proxy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProxyhubMetricsService
{
    public function todayForInstances(array $instanceIds): array
    {
        $instanceIds = array_values(array_filter($instanceIds));

        if (empty($instanceIds)) {
            return [
                'sentToday' => 0,
                'receivedToday' => 0,
            ];
        }

        $url = ProxyhubConfig::baseUrl() . '/v1/metrics?instanceIds=' . implode(',', $instanceIds);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . ProxyhubConfig::integratorToken(),
            ])->connectTimeout(15)
                ->timeout(20)
                ->retry(2, 500)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('ProxyHub metrics request failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return $this->failedMetrics();
            }

            $json = $response->json() ?: [];

            return [
                'sentToday' => (int) ($json['sentToday'] ?? 0),
                'receivedToday' => (int) ($json['receivedToday'] ?? 0),
            ];
        } catch (\Throwable $th) {
            Log::warning('ProxyHub metrics request error', [
                'error' => $th->getMessage(),
            ]);

            return $this->failedMetrics();
        }
    }

    private function failedMetrics(): array
    {
        return [
            'sentToday' => null,
            'receivedToday' => null,
        ];
    }
}
