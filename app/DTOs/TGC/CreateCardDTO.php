<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateCardDTO
{
    public function __construct(
        public string $deckId,
        public string $name,
        public string $faceFileId,
        public ?string $backFileId = null,
        public int $hasProofedFace = 1,
        public int $hasProofedBack = 1,
    ) {
        if (trim($this->deckId) === '' || trim($this->name) === '' || trim($this->faceFileId) === '') {
            throw new InvalidArgumentException('deck_id, name and face_file_id are required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            deckId: (string) $request->route('deckId', $request->input('deck_id')),
            name: (string) $request->string('name'),
            faceFileId: (string) $request->input('face_file_id'),
            backFileId: $request->filled('back_file_id') ? (string) $request->input('back_file_id') : null,
        );
    }
}