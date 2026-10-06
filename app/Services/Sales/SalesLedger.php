<?php

namespace App\Services\Sales;

use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowPayment;
use App\Models\FlowReceipt;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a sale down once, at the moment it is confirmed.
 *
 * Two things confirm one today: an AI accepting a proof of payment (a Receipt
 * node) and a gateway saying a charge was paid (a Payment node, or the charge
 * an AI agent raised for a cart). Both end here.
 *
 * What kind of sale it was — the front offer or an upsell, and which offer —
 * is what the flow's author set on the node that sold it. Nothing guesses:
 * a sale from a node nobody labelled is recorded without a kind and shown as
 * unclassified, which is more useful than a confident wrong answer.
 *
 * Never throws at its caller. A sale missing from a report is a problem; a
 * customer's paid flow stopping because the report could not be written is a
 * worse one.
 */
final class SalesLedger
{
    public static function fromReceipt(FlowReceipt $receipt): void
    {
        if ($receipt->status !== FlowReceipt::STATUS_APPROVED || ! $receipt->amount_cents) {
            return;
        }

        self::write(Sale::SOURCE_RECEIPT, $receipt->id, [
            'tenant_id' => $receipt->tenant_id,
            'conversation_id' => $receipt->conversation_id,
            'amount_cents' => $receipt->amount_cents,
            'currency' => $receipt->currency ?: 'BRL',
            'sold_at' => $receipt->created_at ?? now(),
        ], $receipt->flow_node_id);
    }

    public static function fromPayment(FlowPayment $payment): void
    {
        if ($payment->paid_at === null || ! $payment->amount_cents) {
            return;
        }

        self::write(Sale::SOURCE_PAYMENT, $payment->id, [
            'tenant_id' => $payment->tenant_id,
            'conversation_id' => $payment->conversation_id,
            'contact_id' => $payment->contact_id,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency ?: 'BRL',
            'sold_at' => $payment->paid_at,
        ], $payment->flow_node_id);
    }

    /**
     * The label a node's author gave its sales.
     *
     * @param  array<string, mixed>  $data  node data
     * @return array{kind: ?string, offer: ?string}
     */
    public static function label(array $data): array
    {
        $kind = $data['sale_kind'] ?? null;
        $offer = trim((string) ($data['sale_offer'] ?? ''));

        return [
            'kind' => in_array($kind, Sale::KINDS, true) ? $kind : null,
            'offer' => $offer !== '' ? Str::limit($offer, 120, '') : null,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private static function write(string $source, int $sourceId, array $attributes, ?int $flowNodeId): void
    {
        try {
            $conversation = isset($attributes['conversation_id'])
                ? Conversation::find($attributes['conversation_id'])
                : null;

            $label = self::label((array) (FlowNode::find($flowNodeId)?->data ?? []));

            Sale::firstOrCreate(
                ['source' => $source, 'source_id' => $sourceId],
                $attributes + $label + [
                    'connection_id' => $conversation?->connection_id,
                    'contact_id' => $attributes['contact_id'] ?? $conversation?->contact_id,
                    'ad_id' => AdReferrals::adIdFor($conversation),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('SalesLedger: a confirmed sale could not be recorded', [
                'source' => $source,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
