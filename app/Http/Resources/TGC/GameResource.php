<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'result.id') ?? data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'result.name') ?? data_get($this->resource, 'name'),
            'description' => data_get($this->resource, 'result.description') ?? data_get($this->resource, 'description'),
            'sku_id' => data_get($this->resource, 'result.sku_id') ?? data_get($this->resource, 'sku_id'),
        ];
    }
}
