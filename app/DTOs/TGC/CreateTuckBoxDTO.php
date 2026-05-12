<?php

namespace App\DTOs\TGC;

class CreateTuckBoxDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $gameId,
        public readonly string $identity = 'PokerTuckBox54',
    ) {}
}