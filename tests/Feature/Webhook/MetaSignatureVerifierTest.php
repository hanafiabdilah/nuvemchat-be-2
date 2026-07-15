<?php

use App\Services\Webhook\MetaSignatureVerifier;
use Illuminate\Http\Request;

function signedRequest(string $body, string $secret): Request
{
    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [], $body);
    $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
    $request->headers->set('X-Hub-Signature-256', $signature);

    return $request;
}

test('accepts a request with a valid signature', function () {
    $body = '{"object":"whatsapp_business_account","entry":[]}';
    $request = signedRequest($body, 'app-secret');

    expect(MetaSignatureVerifier::verify($request, 'app-secret', 'whatsapp'))->toBeTrue();
});

test('rejects a request whose body was tampered with', function () {
    // Signature computed over the original body, but the delivered body differs.
    $signature = signedRequest('{"object":"whatsapp_business_account"}', 'app-secret')
        ->header('X-Hub-Signature-256');

    $tampered = Request::create('/webhook/whatsapp', 'POST', [], [], [], [], '{"object":"forged"}');
    $tampered->headers->set('X-Hub-Signature-256', $signature);

    expect(MetaSignatureVerifier::verify($tampered, 'app-secret', 'whatsapp'))->toBeFalse();
});

test('rejects a request signed with the wrong secret', function () {
    $body = '{"object":"whatsapp_business_account"}';
    $request = signedRequest($body, 'attacker-secret');

    expect(MetaSignatureVerifier::verify($request, 'app-secret', 'whatsapp'))->toBeFalse();
});

test('rejects a request with a missing signature header', function () {
    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [], '{}');

    expect(MetaSignatureVerifier::verify($request, 'app-secret', 'whatsapp'))->toBeFalse();
});

test('rejects a request with a malformed signature header', function () {
    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [], '{}');
    $request->headers->set('X-Hub-Signature-256', 'deadbeef');

    expect(MetaSignatureVerifier::verify($request, 'app-secret', 'whatsapp'))->toBeFalse();
});

test('skips verification when no secret is configured', function () {
    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [], '{}');

    expect(MetaSignatureVerifier::verify($request, null, 'whatsapp'))->toBeTrue();
    expect(MetaSignatureVerifier::verify($request, '', 'whatsapp'))->toBeTrue();
});
