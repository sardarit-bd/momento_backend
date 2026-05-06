<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateCardFromFaceDTO
{
    public function __construct(
        public string $name,
        public string $deckId,
        public string $faceId,
        public int $hasProofedFace,
        public int $hasProofedBack,
    ) {
        if (trim($this->name) === '' || trim($this->deckId) === '' || trim($this->faceId) === '') {
            throw new InvalidArgumentException('name, deck_id and face_id are required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: (string) $request->string('name'),
            deckId: (string) $request->input('deck_id'),
            faceId: (string) $request->input('face_id'),
            hasProofedFace: (int) $request->input('has_proofed_face', 1),
            hasProofedBack: (int) $request->input('has_proofed_back', 1),
        );
    }
}
