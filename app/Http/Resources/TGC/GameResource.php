<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'name' => data_get($this->resource, 'name') ?? data_get($this->resource, 'result.name'),
            'description' => data_get($this->resource, 'description') ?? data_get($this->resource, 'result.description'),
        ];
    }
}
