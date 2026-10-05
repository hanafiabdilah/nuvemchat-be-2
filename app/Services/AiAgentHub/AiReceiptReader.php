<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\MessageType;
use App\Models\AiHubAgent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Flow\PaymentNodes;
use App\Services\Media\MediaStorage;
use App\Services\Media\PdfPreview;
use Illuminate\Support\Facades\Log;

/**
 * Has one of the workspace's AI agents read a proof of payment.
 *
 * A run like any other — the workspace's own provider key or a rented one, the
 * same balance gate, an `ai_hub_runs` row — but in a hub conversation of its
 * own, so the instruction below never becomes part of what the agent remembers
 * about the customer. And never retried without the picture: an answer about a
 * receipt the model did not see is not a weaker answer, it is an invented one.
 *
 * The model reports what the document says. Whether that is enough is decided
 * by the caller, in code, from the fields — the model is not asked for a
 * verdict, because a verdict cannot be checked and a number can.
 */
class AiReceiptReader
{
    public function __construct(
        private readonly AiAgentHubTenantService $hub,
    ) {}

    /**
     * @return array{
     *     readable: bool, is_receipt: bool, completed: bool, amount_cents: ?int,
     *     currency: ?string, payer: ?string, recipient: ?string, paid_on: ?string,
     *     transaction_id: ?string, recipient_matches: ?bool, note: ?string,
     *     run_id: ?int, raw: ?array
     * }
     */
    public function read(
        AiHubAgent $agent,
        Conversation $conversation,
        Message $message,
        ?string $expectedRecipient = null,
        ?int $flowStateId = null,
        ?int $flowNodeId = null,
    ): array {
        $unreadable = self::blank();
        $preview = null;

        try {
            $attachment = AiAttachments::forMessage($message);

            if (($attachment['type'] ?? null) !== 'image') {
                $preview = $this->pdfPreview($message);
                $attachment = $preview ? ['type' => 'image', 'detail' => 'auto', 'url' => $preview['url'], 'name' => 'receipt.png'] : null;
            }

            if ($attachment === null) {
                return $unreadable;
            }

            $run = $this->hub->runAgent(
                $agent,
                $conversation,
                $this->instruction($expectedRecipient),
                $flowStateId,
                $flowNodeId,
                ['purpose' => 'receipt_check'],
                conversationExternalId: "receipt:{$conversation->id}:m{$message->id}",
                attachments: [$attachment],
                retryWithoutExtras: false,
            );

            $answer = $this->decode((string) $run->output_message);

            if ($answer === null) {
                Log::warning('AiReceiptReader: the model did not answer in the expected format', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'ai_hub_run_id' => $run->id,
                ]);

                throw new \RuntimeException('The receipt reader returned no structured answer.');
            }

            $amount = is_scalar($answer['amount'] ?? null) ? PaymentNodes::parseAmount((string) $answer['amount']) : null;

            return [
                'readable' => true,
                'is_receipt' => ($answer['is_receipt'] ?? false) === true,
                'completed' => ($answer['completed'] ?? false) === true,
                'amount_cents' => $amount,
                'currency' => self::text($answer['currency'] ?? null, 3),
                'payer' => self::text($answer['payer'] ?? null),
                'recipient' => self::text($answer['recipient'] ?? null) ?? self::text($answer['recipient_key'] ?? null),
                'paid_on' => self::text($answer['date'] ?? null, 20),
                'transaction_id' => self::text($answer['transaction_id'] ?? null, 120),
                'recipient_matches' => is_bool($answer['recipient_matches'] ?? null) ? $answer['recipient_matches'] : null,
                'note' => self::text($answer['note'] ?? null),
                'run_id' => $run->id,
                'raw' => $answer,
            ];
        } finally {
            PdfPreview::discard($preview);
        }
    }

    /** @return array{path: string, url: string}|null */
    private function pdfPreview(Message $message): ?array
    {
        if ($message->message_type !== MessageType::Document
            || ! $message->attachment
            || strtolower(pathinfo(strtok($message->attachment, '?') ?: '', PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }

        $bytes = MediaStorage::disk()->get($message->attachment);

        return $bytes ? PdfPreview::firstPage($bytes) : null;
    }

    /**
     * ⚠️ Worded without the vocabulary the hub scans customer messages for
     * (asking for a person, an attendant, support): this text travels as the
     * run's USER message.
     */
    private function instruction(?string $expectedRecipient): string
    {
        $recipientRule = $expectedRecipient !== null && $expectedRecipient !== ''
            ? 'The payment is expected to have been made to: "'.$expectedRecipient.'". Set "recipient_matches" to true when the '
                .'recipient name or key on the document clearly refers to it (ignore case, accents, company suffixes such as '
                .'LTDA or ME, and partly masked digits), false when the document clearly names someone else, and null when '
                .'the document does not show a recipient.'
            : 'Set "recipient_matches" to null.';

        return <<<PROMPT
        [Automated document check. This is not a message from the customer and needs no conversational reply.]

        Look at the attached image and report what it shows. The question is whether it is proof of a payment that has
        already been completed — a bank, Pix, transfer or card receipt, or a confirmation screen — and what it says.

        Reply with ONE JSON object and nothing else, no code fence:

        {"is_receipt": true, "completed": true, "amount": "49.90", "currency": "BRL", "payer": null, "recipient": null,
         "recipient_key": null, "date": "2026-01-31", "transaction_id": null, "recipient_matches": null, "note": ""}

        - "is_receipt": false for anything that is not a payment document: a photo, a chat screenshot, a product picture.
        - "completed": false when the payment is scheduled, pending, cancelled or refused, and for a charge still waiting
          to be paid (a boleto, a QR code, an invoice, a payment request).
        - "amount": the amount paid, digits with a dot for decimals and no thousands separator. null if it cannot be read.
        - "recipient": who received the money, as written. "recipient_key": their Pix key or account, if shown.
        - "transaction_id": the transaction, authentication or end-to-end id, as written.
        - "date": the day of the payment, as YYYY-MM-DD.
        - Never guess. A field you cannot read on the document is null.
        - "note": one short sentence on anything that looks wrong, or "".

        {$recipientRule}
        PROMPT;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) && array_key_exists('is_receipt', $decoded) ? $decoded : null;
    }

    private static function text(mixed $value, int $max = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return [
            'readable' => false, 'is_receipt' => false, 'completed' => false, 'amount_cents' => null,
            'currency' => null, 'payer' => null, 'recipient' => null, 'paid_on' => null,
            'transaction_id' => null, 'recipient_matches' => null, 'note' => null,
            'run_id' => null, 'raw' => null,
        ];
    }
}
