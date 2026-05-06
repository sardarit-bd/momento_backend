<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\Services\TGC\TGCService;

class AddSkuToCartAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(AddToCartDTO $dto): array
    {
        return $this->service->addSkuToCart($dto);
    }
}
