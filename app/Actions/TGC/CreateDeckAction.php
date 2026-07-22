<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateDeckDTO;
use App\Services\TGC\TGCService;

class CreateDeckAction
{
    public function __construct(private readonly TGCService $service) {}

    public function handle(CreateDeckDTO $dto): array
    {
        return $this->service->createDeck($dto);
    }
}
