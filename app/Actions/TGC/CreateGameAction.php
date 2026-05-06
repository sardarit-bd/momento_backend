<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateGameDTO;
use App\Services\TGC\TGCService;

class CreateGameAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(CreateGameDTO $dto): array
    {
        return $this->service->createGame($dto);
    }
}
