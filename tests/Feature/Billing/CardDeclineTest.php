<?php

use App\Services\Billing\CardDecline;

/*
 * Every gateway's refusal, folded into one reason the cardholder can act on.
 * The same reason whether it came from Stripe, Mercado Pago or an acquirer's
 * ISO code — and never a reason that tells a fraudster which card was flagged.
 */

it('names what the cardholder can do about a refusal, whichever gateway said it', function (?array $decline, string $reason) {
    expect(CardDecline::reason($decline))->toBe($reason);
})->with([
    'stripe, no funds' => [['code' => 'insufficient_funds'], CardDecline::INSUFFICIENT_FUNDS],
    'mercado pago, no funds' => [['code' => 'cc_rejected_insufficient_amount'], CardDecline::INSUFFICIENT_FUNDS],
    'acquirer, no funds' => [['code' => '51'], CardDecline::INSUFFICIENT_FUNDS],
    'stripe, wrong cvc' => [['code' => 'incorrect_cvc'], CardDecline::INCORRECT_CVC],
    'mercado pago, wrong cvc' => [['code' => 'cc_rejected_bad_filled_security_code'], CardDecline::INCORRECT_CVC],
    'acquirer, wrong cvc' => [['code' => 'N7'], CardDecline::INCORRECT_CVC],
    'expired' => [['code' => 'expired_card'], CardDecline::EXPIRED_CARD],
    'wrong number' => [['code' => 'cc_rejected_bad_filled_card_number'], CardDecline::INCORRECT_NUMBER],
    'bank wants the cardholder' => [['code' => 'authentication_required'], CardDecline::AUTHENTICATION_REQUIRED],
    'call the bank' => [['code' => 'do_not_honor'], CardDecline::CALL_ISSUER],
    'card disabled' => [['code' => 'cc_rejected_card_disabled'], CardDecline::CALL_ISSUER],
    'too many tries' => [['code' => 'card_velocity_exceeded'], CardDecline::LIMIT_EXCEEDED],
    'bank unavailable' => [['code' => 'processing_error'], CardDecline::TRY_AGAIN_LATER],
    'unknown code, issuer advice' => [['code' => 'something_new', 'advice' => 'try_again_later'], CardDecline::TRY_AGAIN_LATER],
    'nothing at all' => [null, CardDecline::DECLINED],
]);

it('never tells the person paying that the card was reported lost, stolen or fraudulent', function (string $code) {
    expect(CardDecline::reason(['code' => $code]))->toBe(CardDecline::DECLINED);
})->with(['lost_card', 'stolen_card', 'pickup_card', 'fraudulent', 'merchant_blacklist', 'cc_rejected_high_risk', 'cc_rejected_blacklist']);

it('has a sentence for every reason', function () {
    foreach (CardDecline::reasons() as $reason) {
        expect(CardDecline::message($reason))->not->toBe('')
            ->not->toContain('_');
    }
});
