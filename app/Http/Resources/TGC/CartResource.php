<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'status' => data_get($this->resource, 'status') ?? data_get($this->resource, 'result.status'),
            'items' => data_get($this->resource, 'items')
                ?? data_get($this->resource, 'result.items')
                ?? [],
        ];
    }
}
