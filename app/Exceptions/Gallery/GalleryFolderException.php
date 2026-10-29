<?php

namespace App\Exceptions\Gallery;

/**
 * A folder change the tree cannot take: a name already used next to it, a
 * folder dropped into itself, a tree deeper than the limit.
 *
 * Carries a stable `code` because the dashboard translates these itself — the
 * sentence here is the Portuguese fallback for a caller that does not — and a
 * `field` so a form can put the error under the input that caused it.
 */
class GalleryFolderException extends \RuntimeException
{
    public const NAME_TAKEN = 'folder_name_taken';

    public const TOO_DEEP = 'folder_too_deep';

    public const LIMIT_REACHED = 'folder_limit_reached';

    public const INTO_ITSELF = 'folder_into_itself';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $field = 'name',
    ) {
        parent::__construct($message);
    }

    public static function nameTaken(string $name): self
    {
        return new self(self::NAME_TAKEN, "Já existe uma pasta chamada \"{$name}\" aqui.");
    }

    public static function tooDeep(int $maxDepth): self
    {
        return new self(self::TOO_DEEP, "As pastas podem ter no máximo {$maxDepth} níveis.", 'parent_id');
    }

    public static function limitReached(int $max): self
    {
        return new self(self::LIMIT_REACHED, "A galeria pode ter no máximo {$max} pastas.");
    }

    public static function intoItself(): self
    {
        return new self(self::INTO_ITSELF, 'Uma pasta não pode ser movida para dentro dela mesma.', 'parent_id');
    }
}
