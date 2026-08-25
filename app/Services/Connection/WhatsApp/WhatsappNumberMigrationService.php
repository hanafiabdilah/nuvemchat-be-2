<?php

namespace App\Services\Connection\WhatsApp;

use App\Models\Connection;
use App\Services\Connection\Meta\GraphApi;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta's programmatic path for bringing a phone number that is live on another
 * BSP onto one of our WABAs.
 *
 * Four calls, in order, each depending on the last:
 *
 *   1. POST /{WABA_ID}/phone_numbers        cc, phone_number, verified_name,
 *                                           migrate_phone_number  → phone number id
 *   2. POST /{PHONE_NUMBER_ID}/request_code code_method, language
 *   3. POST /{PHONE_NUMBER_ID}/verify_code  code
 *   4. POST /{PHONE_NUMBER_ID}/register     messaging_product, pin
 *
 * Why this exists alongside the Embedded Signup route: ES asks the business to
 * type the number inside Meta's own window, which works but is invisible —
 * there is no migration screen, nothing is listed, and a failure looks
 * identical to an ordinary connect that the customer abandoned. This path is
 * the one every BSP with a visible "migrate your number" feature implements,
 * because each step reports its own error and can be retried on its own.
 *
 * Permissions: `whatsapp_business_management` on a User, System User or
 * Business Integration System User token — which is exactly what Embedded
 * Signup already hands us. No Solution Partner status, no credit line.
 *
 * ⚠️ `migrate_phone_number` is documented by Meta's phone-number reference as
 * the flag for an On-Premises → Cloud API move, while the solution-migration
 * guide uses it for moving a number between WABAs. Both readings amount to the
 * same instruction — "this number already exists somewhere, take it over
 * rather than creating a new one" — so it is sent here, and step 1 logs Meta's
 * response body verbatim on failure. The first real migration settles it.
 */
class WhatsappNumberMigrationService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    /** How Meta may deliver the verification code. */
    public const CODE_METHODS = ['SMS', 'VOICE'];

    /**
     * Step 1 — claim the number onto our WABA.
     *
     * Returns the new phone number id. This id belongs to *our* WABA and is
     * not the one the losing provider used: nothing about the old registration
     * carries over except the number itself and what Meta migrates with it
     * (display name, quality rating, approved templates).
     *
     * @return array{phone_number_id: string}
     */
    public function claimNumber(Connection $connection, string $countryCode, string $phoneNumber, string $verifiedName): array
    {
        [$wabaId, $token] = $this->wabaCredentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$wabaId}/phone_numbers", [
                'cc' => $countryCode,
                'phone_number' => $phoneNumber,
                'verified_name' => $verifiedName,
                'migrate_phone_number' => true,
            ]));

        if (! $response->successful()) {
            // Verbatim, because this is the one call whose exact contract is
            // still unproven — a paraphrased error would cost another round.
            Log::error('WhatsApp migration: claiming the number failed', [
                'connection_id' => $connection->id,
                'waba_id' => $wabaId,
                'cc' => $countryCode,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->fail($response, $this->claimHint($response));
        }

        $phoneNumberId = $response->json('id');

        if (! $phoneNumberId) {
            throw new RuntimeException('Meta accepted the number but returned no id.');
        }

        Log::info('WhatsApp migration: number claimed', [
            'connection_id' => $connection->id,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
        ]);

        return ['phone_number_id' => (string) $phoneNumberId];
    }

    /** Step 2 — ask Meta to send the verification code to the number. */
    public function requestCode(Connection $connection, string $phoneNumberId, string $codeMethod, string $language = 'en_US'): void
    {
        $token = $this->token($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$phoneNumberId}/request_code", [
                'code_method' => $codeMethod,
                'language' => $language,
            ]));

        if (! $response->successful()) {
            Log::error('WhatsApp migration: requesting the code failed', [
                'connection_id' => $connection->id,
                'phone_number_id' => $phoneNumberId,
                'code_method' => $codeMethod,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->fail($response, $this->requestCodeHint($response));
        }
    }

    /**
     * Step 3 — hand back the code the business received.
     *
     * Meta's reference notes the code arrives hyphenated and must be sent
     * without it, so the separator is stripped here rather than being one more
     * thing for the person typing it to get wrong.
     */
    public function verifyCode(Connection $connection, string $phoneNumberId, string $code): void
    {
        $token = $this->token($connection);
        $normalized = preg_replace('/\D/', '', $code) ?? $code;

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$phoneNumberId}/verify_code", [
                'code' => $normalized,
            ]));

        if (! $response->successful()) {
            Log::error('WhatsApp migration: verifying the code failed', [
                'connection_id' => $connection->id,
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->fail($response, $this->verifyCodeHint($response));
        }
    }

    /**
     * Step 4 — register the verified number for Cloud API under our WABA.
     *
     * The PIN becomes the number's two-step verification PIN and must be kept:
     * any later re-registration needs the same value.
     */
    public function register(Connection $connection, string $phoneNumberId, string $pin): void
    {
        $token = $this->token($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]));

        if ($response->successful()) {
            return;
        }

        // Already registered is the one failure that is really a success: a
        // retried step, or a number Meta registered as part of the migration.
        $subcode = (int) $response->json('error.error_subcode');
        $message = (string) $response->json('error.message');

        if ($subcode === 2388023 || stripos($message, 'already registered') !== false) {
            Log::info('WhatsApp migration: number was already registered', [
                'connection_id' => $connection->id,
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        Log::error('WhatsApp migration: registering the number failed', [
            'connection_id' => $connection->id,
            'phone_number_id' => $phoneNumberId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $this->fail($response, $this->registerHint((int) $response->json('error.code')));
    }

    /** The number's details on our WABA, once it is ours. */
    public function phoneDetails(Connection $connection, string $phoneNumberId): array
    {
        $token = $this->token($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->get(self::GRAPH_BASE . "/{$phoneNumberId}", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type',
            ]));

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    /* ------------------------------------------------------------------
     | Turning Meta's codes into something the business can act on
     * ------------------------------------------------------------------ */

    private function claimHint(Response $response): ?string
    {
        $code = (int) $response->json('error.code');
        $message = (string) $response->json('error.message');

        // 100/subcode varies; Meta words this one differently across versions,
        // so the text is checked as well as the code.
        if ($response->status() === 409 || stripos($message, 'already') !== false) {
            return 'This number is still attached to another WhatsApp Business Account. '
                . 'Ask your current provider to disable two-step verification for it — and, if they enabled '
                . 'data localization, to remove that too — then try again.';
        }

        return match ($code) {
            100 => 'Meta rejected the number or display name. Check the country code and number are correct, '
                . 'and that the display name matches the one already approved for this number.',
            200 => 'This account is not allowed to add that number. Confirm you are an admin of the business '
                . 'portfolio that owns it, and that the business is verified.',
            default => null,
        };
    }

    private function requestCodeHint(Response $response): ?string
    {
        return match ((int) $response->json('error.code')) {
            136024 => 'Meta could not send the verification code to this number. '
                . 'If the number is still active on the other provider, ask them to release it first; '
                . 'otherwise try the other delivery method (voice call instead of SMS).',
            100 => 'Meta rejected the verification request. Confirm the number was claimed successfully '
                . 'before asking for a code.',
            default => null,
        };
    }

    private function verifyCodeHint(Response $response): ?string
    {
        $message = (string) $response->json('error.message');

        if (stripos($message, 'expire') !== false) {
            return 'That code has expired. Request a new one and enter it within a few minutes.';
        }

        return match ((int) $response->json('error.code')) {
            100 => 'That code was not accepted. Check the digits and request a new code if it was sent a while ago.',
            default => null,
        };
    }

    private function registerHint(int $code): ?string
    {
        return match ($code) {
            133005 => 'This number still has two-step verification enabled. Ask your current provider to '
                . 'disable it, then retry — the number has already been claimed, so only this step repeats.',
            133006 => 'This number has not completed verification. Finish the verification step first.',
            133016 => 'Meta is rate-limiting registration for this number. Wait a few minutes and retry.',
            default => null,
        };
    }

    /**
     * @return array{0:string,1:string}  [wabaId, accessToken]
     */
    private function wabaCredentials(Connection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $wabaId = $credentials['business_account_id'] ?? null;
        $token = $credentials['access_token'] ?? null;

        if (! $wabaId || ! $token) {
            throw new RuntimeException(
                'This connection has no WhatsApp Business Account yet. Authorize with Meta first, then migrate the number.'
            );
        }

        return [(string) $wabaId, (string) $token];
    }

    private function token(Connection $connection): string
    {
        return $this->wabaCredentials($connection)[1];
    }

    /** Prefer our own wording; fall back to Meta's rather than inventing one. */
    private function fail(Response $response, ?string $hint): never
    {
        $message = $hint
            ?? $response->json('error.message')
            ?? 'WhatsApp rejected the request.';

        throw new RuntimeException($message, $response->status() ?: 400);
    }
}
