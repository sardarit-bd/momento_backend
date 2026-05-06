<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateDeckDTO
{
    public function __construct(
        public string $gameId,
        public string $name,
        public string $identity = 'PokerDeck',
        public int $hasProofedBack = 1,
    ) {
        if (trim($this->gameId) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('game_id and name are required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            gameId: (string) $request->route('gameId', $request->input('game_id')),
            name: (string) $request->string('name'),
            identity: (string) $request->input('identity', 'PokerDeck'),
            hasProofedBack: (int) $request->input('has_proofed_back', 1),
        );
    }
}
