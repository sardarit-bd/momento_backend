<?php

namespace App\Actions\TGC;

use App\Services\TGC\TGCService;

class CreateCartAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(): array
    {
        return $this->service->createCart();
    }
}
