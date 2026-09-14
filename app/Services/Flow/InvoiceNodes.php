<?php

namespace App\Services\Flow;

/**
 * The Invoice (nota fiscal) node's vocabulary.
 *
 * Mirrored on the frontend in `lib/invoiceNodes.ts` — the branch names are
 * source handle ids and edge condition_values at once, so both sides spell them
 * identically.
 *
 * What the node does: ask the workspace's own issuing platform for a nota
 * fiscal, wait for the authority to authorize it, then send the customer the
 * document and take `issued`. A rejection — or an authority that has not
 * answered by the deadline — takes `failed`. The customer writing in the
 * meantime does not move it.
 */
final class InvoiceNodes
{
    /** The authority authorized the document. */
    public const BRANCH_ISSUED = 'issued';

    /** Rejected, could not be requested, or still unanswered at the deadline. */
    public const BRANCH_FAILED = 'failed';

    public const BRANCHES = [self::BRANCH_ISSUED, self::BRANCH_FAILED];

    public const DEFAULT_WAIT_MINUTES = 30;

    public const MIN_WAIT_MINUTES = 1;

    /**
     * Three days. Past that the flow has been parked long enough that whatever
     * comes next would arrive out of context — and the sweep keeps checking the
     * invoice itself for a week regardless.
     */
    public const MAX_WAIT_MINUTES = 4320;

    /**
     * How long an invoice the flow stopped waiting for is still followed up at
     * the provider, so a late authorization is recorded (and noted) rather than
     * silently never learned about.
     */
    public const FOLLOW_UP_DAYS = 7;

    /** Variables the node writes into the flow state. */
    public const VARIABLES = [
        'invoice_status',
        'invoice_amount',
        'invoice_number',
        'invoice_pdf_url',
        'invoice_xml_url',
        'invoice_id',
        'invoice_error',
    ];

    /** Info-note codes, rendered in the reader's language by `lib/infoMessage.ts`. */
    public const INFO_REQUESTED = 'flow_invoice_requested';

    public const INFO_ISSUED = 'flow_invoice_issued';

    public const INFO_FAILED = 'flow_invoice_failed';

    /** The deadline passed with the authority still silent; the flow moved on. */
    public const INFO_STILL_PROCESSING = 'flow_invoice_still_processing';

    /** Authorized after the flow had already taken the failed branch. */
    public const INFO_ISSUED_LATE = 'flow_invoice_issued_late';

    public const INFO_CANCELLED = 'flow_invoice_cancelled';

    /** Where the flow state remembers which invoice this node is waiting on. */
    public static function stateKey(int $nodeId): string
    {
        return "_invoice_{$nodeId}";
    }

    /** @param  array<string, mixed>  $data */
    public static function waitMinutes(array $data): int
    {
        $minutes = (int) ($data['wait_minutes'] ?? self::DEFAULT_WAIT_MINUTES);

        if ($minutes <= 0) {
            $minutes = self::DEFAULT_WAIT_MINUTES;
        }

        return max(self::MIN_WAIT_MINUTES, min($minutes, self::MAX_WAIT_MINUTES));
    }

    /** @param  array<string, mixed>  $data */
    public static function sendsPdf(array $data): bool
    {
        return ($data['send_pdf'] ?? true) !== false;
    }

    /** @param  array<string, mixed>  $data */
    public static function sendsEmail(array $data): bool
    {
        return ($data['send_email'] ?? true) !== false;
    }

    /** A CPF or CNPJ reduced to its digits — or null when it is neither. */
    public static function document(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        return in_array(strlen((string) $digits), [11, 14], true) ? $digits : null;
    }

    /**
     * The customer's address, when the author filled any of it in. Many
     * prefeituras accept an NFS-e for a person without one; a CNPJ usually
     * needs it.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string): string  $interpolate
     * @return array<string, string>|null
     */
    public static function address(array $data, callable $interpolate): ?array
    {
        $raw = (array) ($data['customer_address'] ?? []);
        $address = [];

        foreach (['postal_code', 'street', 'number', 'complement', 'district', 'city', 'state'] as $key) {
            $value = trim($interpolate((string) ($raw[$key] ?? '')));

            if ($value !== '') {
                $address[$key] = $key === 'postal_code' ? (string) preg_replace('/\D+/', '', $value) : $value;
            }
        }

        if (isset($address['state'])) {
            $address['state'] = strtoupper(substr($address['state'], 0, 2));
        }

        return $address === [] ? null : $address;
    }
}
