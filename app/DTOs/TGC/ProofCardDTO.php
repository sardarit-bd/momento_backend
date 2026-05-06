<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class ProofCardDTO
{
    public function __construct(
        public string $cardId,
        public bool $hasProofedFace,
        public bool $hasProofedBack,
    ) {
        if (trim($this->cardId) === '') {
            throw new InvalidArgumentException('card_id is required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        $cardData = $request->input('card', []);
        $hasBack = data_get($cardData, 'back_id') !== null;

        return new self(
            cardId: (string) $request->route('cardId', $request->input('card_id')),
            hasProofedFace: true,
            hasProofedBack: $hasBack,
        );
    }
}
