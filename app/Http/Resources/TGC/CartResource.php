<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'result.result.id')
                ?? data_get($this->resource, 'result.id')
                ?? data_get($this->resource, 'id'),
            'status' => data_get($this->resource, 'result.result.payment_status')
                ?? data_get($this->resource, 'result.status')
                ?? data_get($this->resource, 'status'),
            'item_count' => data_get($this->resource, 'result.result.item_count')
                ?? data_get($this->resource, 'result.item_count')
                ?? 0,
            'total' => data_get($this->resource, 'result.result.grand_total')
                ?? data_get($this->resource, 'result.grand_total')
                ?? '0.00',
            'items' => data_get($this->resource, 'result.result.items')
                ?? data_get($this->resource, 'result.items')
                ?? [],
        ];
    }
}
