<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\CreateAddressDTO;
use App\Services\TGC\TGCService;
use App\Services\TGC\TGCSessionManager;

class CreateAddressAction
{
    public function __construct(
        private readonly TGCService $service,
        private readonly TGCSessionManager $sessionManager,
    ) {}

    public function handle(CreateAddressDTO $dto): array
    {
        return $this->service->createAddress($dto);
    }
}
