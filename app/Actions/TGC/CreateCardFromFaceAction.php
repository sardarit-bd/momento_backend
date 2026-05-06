<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\Services\TGC\TGCService;

class CreateCardFromFaceAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(CreateCardFromFaceDTO $dto): array
    {
        return $this->service->createCardFromFace($dto);
    }
}
