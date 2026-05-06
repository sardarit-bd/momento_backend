<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\ProofCardDTO;
use App\Services\TGC\TGCService;

class ProofCardAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(ProofCardDTO $dto): array
    {
        return $this->service->proofCard($dto);
    }
}
