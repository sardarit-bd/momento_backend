<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateCardDTO;
use App\Services\TGC\TGCService;

class CreateCardAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(CreateCardDTO $dto): array
    {
        return $this->service->createCard($dto);
    }
}
