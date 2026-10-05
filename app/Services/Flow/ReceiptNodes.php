<?php

namespace App\Services\Flow;

/**
 * The Receipt node: ask for proof of payment, have an AI read it, and branch
 * on what it says.
 *
 * It exists for sales closed by hand in the chat — a Pix key typed into a
 * message, a transfer to an account — where no gateway is there to say the
 * money arrived, so the only evidence is the picture the customer sends. The
 * node reads the amount off that picture, which is also what lets a purchase
 * be reported to an ads pixel with its real value.
 *
 * ⚠️ Reading a receipt is not confirming a payment. A picture can be edited,
 * and nothing here talks to a bank. The node raises the cost of the lazy
 * frauds (wrong amount, somebody else's receipt, the same file twice) and
 * leaves the rest to the business checking its statement; a flow that hands
 * over something valuable on `approved` alone is trusting an image.
 *
 * Mirrored in the builder by lib/receiptNodes.ts — the two must agree.
 */
final class ReceiptNodes
{
    public const BRANCH_APPROVED = 'approved';

    public const BRANCH_REJECTED = 'rejected';

    public const BRANCH_TIMEOUT = 'timeout';

    public const BRANCHES = [self::BRANCH_APPROVED, self::BRANCH_REJECTED, self::BRANCH_TIMEOUT];

    public const MAX_ATTEMPTS = 5;

    public const DEFAULT_ATTEMPTS = 2;

    public const MAX_MESSAGE_LENGTH = 4096;

    /** Why a receipt was turned down. Codes, translated in the dashboard. */
    public const REASONS = [
        'not_configured',
        'unreadable',
        'not_a_receipt',
        'not_completed',
        'amount_unreadable',
        'amount_mismatch',
        'recipient_mismatch',
        'duplicate',
        'ai_unavailable',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'ai_hub_agent_id' => null,
            'message' => '',
            'expected_amount' => '',
            'expected_recipient' => '',
            'invalid_message' => '',
            'max_attempts' => self::DEFAULT_ATTEMPTS,
            'timeout_seconds' => 0,
            'timeout_unit' => 'minutes',
            'pixel_integration_ids' => [],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function agentId(array $data): ?int
    {
        $id = (int) ($data['ai_hub_agent_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function maxAttempts(array $data): int
    {
        $attempts = (int) ($data['max_attempts'] ?? self::DEFAULT_ATTEMPTS);

        return max(1, min(self::MAX_ATTEMPTS, $attempts ?: self::DEFAULT_ATTEMPTS));
    }

    /** @param  array<string, mixed>  $data */
    public static function timeoutSeconds(array $data): int
    {
        return WaitResponseNodes::timeoutSeconds($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function pixelIntegrationIds(array $data): array
    {
        return PixelNodes::integrationIds(['integration_ids' => $data['pixel_integration_ids'] ?? []]);
    }

    /** `{watermark, attempts, checking, reminded}` while the node waits. */
    public static function stateKey(int $nodeId): string
    {
        return "_receipt_{$nodeId}";
    }

    public static function timeoutKey(int $nodeId): string
    {
        return "_receipt_timeout_{$nodeId}";
    }

    /** "49,90" for a message, whatever the currency's own habits are. */
    public static function displayAmount(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    /** "49.90" for a pixel value or an HTTP body. */
    public static function plainAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
