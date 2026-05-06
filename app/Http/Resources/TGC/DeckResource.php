<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'game_id' => data_get($this->resource, 'game_id') ?? data_get($this->resource, 'result.game_id'),
            'name' => data_get($this->resource, 'name') ?? data_get($this->resource, 'result.name'),
            'identity' => data_get($this->resource, 'identity') ?? data_get($this->resource, 'result.identity'),
            'has_proofed_back' => data_get($this->resource, 'has_proofed_back') ?? data_get($this->resource, 'result.has_proofed_back'),
        ];
    }
}
