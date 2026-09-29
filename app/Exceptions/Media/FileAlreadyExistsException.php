<?php

namespace App\Exceptions\Media;

use Exception;

/**
 * The workspace already has a file under this name, and nobody has said what to
 * do about it.
 *
 * Carries what the dialog needs to ask the question properly: a person deciding
 * between "replace" and "keep both" is really asking *which* file is already
 * there, so the size and the date travel with the refusal.
 */
class FileAlreadyExistsException extends Exception
{
    /**
     * @param  array{url: string, path: string, size: int|null, modified_at: string|null}  $existing
     */
    public function __construct(
        public readonly string $filename,
        public readonly array $existing,
    ) {
        parent::__construct("Já existe um arquivo chamado \"{$filename}\" neste workspace.");
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'file_exists',
            'filename' => $this->filename,
            'existing' => $this->existing,
            'options' => \App\Enums\Media\UploadConflict::values(),
        ];
    }
}
