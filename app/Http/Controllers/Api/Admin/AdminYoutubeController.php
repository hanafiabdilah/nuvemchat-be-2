<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Youtube\YoutubeConfig;
use Illuminate\Support\Facades\Http;

/**
 * Proves the Google OAuth client works before any workspace tries it.
 *
 * There is no token to test with — tokens belong to the channels workspaces
 * connect — so this asks the token endpoint to redeem a code that does not
 * exist. Google checks the client before the code, and its answer separates
 * the three things an operator can get wrong:
 *
 *  - `invalid_client`         → the id or the secret is wrong;
 *  - `redirect_uri_mismatch`  → our callback is not registered on the client;
 *  - `invalid_grant`          → both are fine (the code, of course, is not).
 *
 * Answered in Google's own words: operators are the people who fix this.
 */
class AdminYoutubeController extends Controller
{
    public function test()
    {
        if (! YoutubeConfig::isConfigured()) {
            return response()->json([
                'message' => 'Fill in the client ID and the client secret first.',
                'code' => 'youtube_not_configured',
            ], 422);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => 'pingly-connection-probe',
                'client_id' => YoutubeConfig::clientId(),
                'client_secret' => YoutubeConfig::clientSecret(),
                'redirect_uri' => YoutubeConfig::redirectUri(),
                'grant_type' => 'authorization_code',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach Google: '.$e->getMessage()], 502);
        }

        $error = (string) ($response->json('error') ?? '');
        $description = (string) ($response->json('error_description') ?? '');

        return match ($error) {
            'invalid_grant' => response()->json([
                'data' => ['ok' => true, 'redirect_uri' => YoutubeConfig::redirectUri()],
                'message' => 'Google accepted the client and the redirect URI.',
            ]),
            'redirect_uri_mismatch' => response()->json([
                'message' => 'The client works, but its Authorized redirect URIs do not include '.YoutubeConfig::redirectUri().'. Add it in the Google Cloud console.',
                'code' => 'redirect_uri_mismatch',
            ], 422),
            'invalid_client', 'unauthorized_client' => response()->json([
                'message' => 'Google refused the client: '.($description ?: $error).'. Check the client ID and secret.',
                'code' => $error,
            ], 422),
            default => response()->json([
                'message' => 'Unexpected answer from Google: '.($description ?: $error ?: 'HTTP '.$response->status()),
                'code' => $error ?: 'unexpected',
            ], 422),
        };
    }
}
