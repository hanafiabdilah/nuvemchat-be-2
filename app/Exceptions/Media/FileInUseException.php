<?php

namespace App\Exceptions\Media;

use App\Exceptions\UserFacingException;
use Illuminate\Http\JsonResponse;

/**
 * A gallery file was asked to be deleted while a flow, a campaign or a product
 * still sends it.
 *
 * Deleting really deletes since every file counts against the storage quota —
 * hiding a tile that still costs space would be a meter nobody can bring down.
 * But the bytes behind a flow node or a campaign are the ones it sends to
 * customers, so a delete that went ahead would break that send somewhere else,
 * silently, weeks later. The refusal names where the file is used, so the
 * person can swap it out there first.
 */
class FileInUseException extends UserFacingException
{
    public const CODE = 'file_in_use';

    /**
     * @param  list<array{type: string, id: int, name: string}>  $usedIn
     */
    public function __construct(public readonly array $usedIn)
    {
        parent::__construct(
            'Este arquivo ainda é usado em um fluxo, campanha ou produto. Troque o arquivo lá antes de excluí-lo.',
            409,
            self::CODE,
        );
    }

    public function toResponse(array $extra = []): JsonResponse
    {
        return parent::toResponse(['used_in' => $this->usedIn, ...$extra]);
    }
}
