<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'deck_id' => data_get($this->resource, 'deck_id') ?? data_get($this->resource, 'result.deck_id'),
            'name' => data_get($this->resource, 'name') ?? data_get($this->resource, 'result.name'),
            'face_id' => data_get($this->resource, 'face_id') ?? data_get($this->resource, 'result.face_id'),
            'back_id' => data_get($this->resource, 'back_id') ?? data_get($this->resource, 'result.back_id'),
            'has_proofed_face' => data_get($this->resource, 'has_proofed_face') ?? data_get($this->resource, 'result.has_proofed_face'),
            'has_proofed_back' => data_get($this->resource, 'has_proofed_back') ?? data_get($this->resource, 'result.has_proofed_back'),
        ];
    }
}
