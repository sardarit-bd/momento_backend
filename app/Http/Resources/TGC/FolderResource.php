<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'name' => data_get($this->resource, 'name') ?? data_get($this->resource, 'result.name'),
            'user_id' => data_get($this->resource, 'user_id') ?? data_get($this->resource, 'result.user_id'),
            'parent_id' => data_get($this->resource, 'parent_id') ?? data_get($this->resource, 'result.parent_id'),
        ];
    }
}
