<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id') ?? data_get($this->resource, 'result.id'),
            'name' => data_get($this->resource, 'name') ?? data_get($this->resource, 'result.name'),
            'folder_id' => data_get($this->resource, 'folder_id') ?? data_get($this->resource, 'result.folder_id'),
            'has_proofed' => data_get($this->resource, 'has_proofed') ?? data_get($this->resource, 'result.has_proofed'),
        ];
    }
}
