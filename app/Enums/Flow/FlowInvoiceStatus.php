<?php

namespace App\Enums\Flow;

/**
 * Where a nota fiscal a flow asked for stands.
 *
 * Only `processing` is open. An invoice is not issued the moment the provider
 * accepts it — the provider still has to send it to the prefeitura (NFS-e) or
 * to SEFAZ (NF-e), and that answer arrives seconds or, on a bad day, hours
 * later. `failed` covers both "the authority rejected it" and "it could not
 * even be sent", for the same reason a payment's `failed` does: the flow takes
 * one branch for both, and the reason on the row tells them apart.
 *
 * `cancelled` is terminal and never produced by the node itself — it is what
 * an invoice cancelled at the provider afterwards reads back as.
 */
enum FlowInvoiceStatus: string
{
    case Processing = 'processing';
    case Issued = 'issued';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this !== self::Processing;
    }
}
