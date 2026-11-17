<?php

namespace App\Exceptions\Media;

use App\Exceptions\UserFacingException;
use Illuminate\Http\JsonResponse;

/**
 * The file would put the workspace over the storage it has.
 *
 * Thrown by every place a person adds a file — the gallery, `POST /api/uploads`
 * (flows, campaigns, products, the API, MCP) and the attachments an agent sends
 * from a conversation — so the whole dashboard answers "full" with one code the
 * front end turns into one dialog: upgrade the plan, or rent more storage.
 *
 * Carries all three numbers for the same reason InsufficientCreditException
 * carries two: a bare "out of space" leaves the customer to work out whether to
 * delete something or rent a gigabyte — and how much of either.
 *
 * ⚠️ Nothing an AI makes is ever refused this way (see GalleryLibrary): a voice
 * reply that turns into text or a flow that takes its failure branch because of
 * a storage meter is worse than a meter that reads over 100%.
 */
class StorageQuotaExceededException extends UserFacingException
{
    public const CODE = 'storage_quota_exceeded';

    public function __construct(
        public readonly int $usedBytes,
        public readonly int $limitBytes,
        public readonly int $requestedBytes,
    ) {
        parent::__construct(
            'Seu armazenamento está cheio. Faça upgrade do plano ou contrate mais armazenamento para adicionar este arquivo.',
            422,
            self::CODE,
        );
    }

    /** Bytes that have to be freed (or rented) before this file fits. */
    public function shortfallBytes(): int
    {
        return max(0, ($this->usedBytes + $this->requestedBytes) - $this->limitBytes);
    }

    public function toResponse(array $extra = []): JsonResponse
    {
        return parent::toResponse([
            'used_bytes' => $this->usedBytes,
            'limit_bytes' => $this->limitBytes,
            'required_bytes' => $this->requestedBytes,
            'shortfall_bytes' => $this->shortfallBytes(),
            ...$extra,
        ]);
    }
}
