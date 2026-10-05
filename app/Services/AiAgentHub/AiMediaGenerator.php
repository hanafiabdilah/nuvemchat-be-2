<?php

namespace App\Services\AiAgentHub;

use App\Models\AiMediaGeneration;
use App\Services\Credits\CreditService;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Talks to the AI Hub's media generation endpoint for one generation at a
 * time: create it, read it again while it runs, and when it is done bring the
 * file home.
 *
 * "Home" matters. The hub's URL for the result expires, and the file may be
 * sent again by a later step or opened from the thread weeks from now, so the
 * bytes are copied to the workspace's published storage and the hub's address
 * is never handed to anyone.
 *
 * Nothing here throws at the caller. A generation ends `completed` or `failed`
 * with a reason code, or stays in flight for the next poll — a network blip on
 * a read is not a failure, and a repeated create is safe because the hub keys
 * it on `externalId`.
 *
 * Contract: PINGLY-MEDIA-GENERATION-20261005.md.
 */
class AiMediaGenerator
{
    /** What the hub's MIME type becomes on disk. */
    private const EXTENSIONS = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/opus' => 'ogg', 'audio/wav' => 'wav', 'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];

    private const FALLBACK_EXTENSION = ['image' => 'png', 'audio' => 'mp3', 'video' => 'mp4'];

    /**
     * Ask the hub to start generating.
     *
     * @param  list<array{url: string, name?: string}>  $inputImages
     */
    public function start(AiMediaGeneration $generation, array $inputImages = []): void
    {
        $credential = $generation->credential;

        if (! $credential || ! $credential->hub_provider_credential_id) {
            $this->fail($generation, 'not_configured');

            return;
        }

        // A rented key is paid from the prepaid balance, and an empty balance
        // buys nothing — checked before the provider is asked to spend.
        if ($credential->isRented() && config('services.billing.enforce')
            && $generation->tenant && ! app(CreditService::class)->canSpend($generation->tenant)) {
            $this->fail($generation, 'credit_exhausted');

            return;
        }

        $request = $generation->request ?? [];

        $payload = array_filter([
            'type' => $generation->type,
            'externalId' => $generation->external_id,
            'providerCredentialId' => $credential->hub_provider_credential_id,
            'model' => $generation->model ?: null,
            'prompt' => $generation->prompt,
            'inputImages' => $inputImages ?: null,
            $generation->type => $request['options'] ?? null,
            'metadata' => ['source' => 'pingly_flow', 'workspace' => $generation->tenant_id],
        ], fn ($value) => $value !== null);

        try {
            $response = Http::timeout(170)->withHeaders($this->headers())->post($this->url(), $payload);
        } catch (ConnectionException $e) {
            // Unknown whether it started. The next poll repeats the create,
            // which the hub answers with the same generation.
            Log::warning('AiMediaGenerator: create did not get an answer, will repeat', [
                'ai_media_generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->apply($generation, $response, 'create');
    }

    /** Read a running generation again. */
    public function refresh(AiMediaGeneration $generation): void
    {
        try {
            $response = Http::timeout(30)->withHeaders($this->headers())
                ->get($this->url().'/'.rawurlencode((string) $generation->hub_generation_id));
        } catch (ConnectionException) {
            return;
        }

        // A read that failed says nothing about the generation itself.
        if ($response->serverError() || $response->status() === 429) {
            return;
        }

        $this->apply($generation, $response, 'read');
    }

    public function fail(AiMediaGeneration $generation, string $code, ?string $message = null): void
    {
        $generation->update([
            'status' => AiMediaGeneration::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message ? Str::limit($message, 1000) : null,
            'completed_at' => now(),
        ]);
    }

    private function apply(AiMediaGeneration $generation, Response $response, string $action): void
    {
        if (! $response->successful()) {
            // The hub's own words go to the log and the row, never to a customer.
            Log::error("AiMediaGenerator: Validation failed to {$action} a media generation", [
                'ai_media_generation_id' => $generation->id,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 2000),
            ]);

            $this->fail(
                $generation,
                in_array($response->status(), [400, 422], true) ? 'invalid_input' : 'provider_error',
                AiAgentHubTenantService::hubMessage($response, "Failed to {$action} a media generation"),
            );

            return;
        }

        $data = $response->json() ?? [];
        $status = strtoupper((string) ($data['status'] ?? ''));

        $generation->fill([
            'hub_generation_id' => $data['id'] ?? $generation->hub_generation_id,
            'provider' => $data['provider'] ?? $generation->provider,
            'model' => $data['model'] ?? $generation->model,
            'cost_usd' => is_numeric($data['providerCostUsd'] ?? null) ? (float) $data['providerCostUsd'] : $generation->cost_usd,
        ]);

        if ($status === 'FAILED') {
            $generation->save();
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $this->fail($generation, $this->reason((string) ($error['code'] ?? '')), (string) ($error['message'] ?? ''));

            return;
        }

        if ($status !== 'COMPLETED') {
            $generation->status = AiMediaGeneration::STATUS_RUNNING;
            $generation->save();

            return;
        }

        $generation->save();
        $this->complete($generation, is_array($data['output'] ?? null) ? $data['output'] : []);
    }

    /**
     * Bring the file home, then charge for it.
     *
     * @param  array<string, mixed>  $output
     */
    private function complete(AiMediaGeneration $generation, array $output): void
    {
        $url = (string) ($output['url'] ?? '');
        $maxBytes = max(1, (int) config('ai.media.max_mb', 64)) * 1024 * 1024;

        try {
            $download = Http::timeout(120)->get($url);
            $bytes = $download->successful() ? $download->body() : '';
        } catch (\Throwable $e) {
            $bytes = '';
        }

        if ($url === '' || $bytes === '' || strlen($bytes) > $maxBytes) {
            Log::error('AiMediaGenerator: the generated file could not be fetched', [
                'ai_media_generation_id' => $generation->id,
                'bytes' => strlen($bytes),
            ]);

            // Charged all the same: the provider made it and billed for it.
            $this->charge($generation);
            $this->fail($generation, 'download_failed');

            return;
        }

        $mime = strtolower(trim(explode(';', (string) ($output['mimeType'] ?? ''))[0]));
        $extension = self::EXTENSIONS[$mime] ?? self::FALLBACK_EXTENSION[$generation->type] ?? 'bin';
        $path = "uploads/{$generation->tenant_id}/ai-media/".Str::lower(Str::random(12))."/{$generation->type}.{$extension}";

        MediaStorage::published()->put($path, $bytes);

        $generation->update([
            'status' => AiMediaGeneration::STATUS_COMPLETED,
            'path' => $path,
            'mime_type' => $mime ?: null,
            'size_bytes' => strlen($bytes),
            'completed_at' => now(),
        ]);

        $this->charge($generation);
    }

    private function charge(AiMediaGeneration $generation): void
    {
        if (! $generation->credential?->isRented()) {
            return;
        }

        try {
            app(CreditService::class)->chargeMedia($generation);
        } catch (\Throwable $e) {
            // The file exists and is on its way to a customer; a bookkeeping
            // failure is not a reason to take it back.
            Log::error('AiMediaGenerator: failed to charge a rented media generation', [
                'ai_media_generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reason(string $code): string
    {
        return in_array($code, ['content_policy', 'invalid_input', 'unsupported', 'credential_invalid', 'timeout'], true)
            ? $code
            : 'provider_error';
    }

    private function url(): string
    {
        return rtrim(AiAgentHubConfig::baseUrl(), '/').'/'.trim((string) config('ai.media.endpoint', 'media-generations'), '/');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.AiAgentHubConfig::tenantToken(),
            'Accept' => 'application/json',
        ];
    }
}
