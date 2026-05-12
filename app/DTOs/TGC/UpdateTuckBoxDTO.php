<?php

namespace App\DTOs\TGC;

class UpdateTuckBoxDTO
{
    public function __construct(
        public readonly string $tuckboxId,
        public readonly string $outsideId,
        public readonly bool   $hasProofedOutside = false,
    ) {}
}