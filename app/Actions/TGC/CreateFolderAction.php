<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateFolderDTO;
use App\Services\TGC\TGCService;

class CreateFolderAction
{
    public function __construct(private readonly TGCService $service) {}

    public function handle(CreateFolderDTO $dto): array
    {
        return $this->service->createFolder($dto);
    }
}
