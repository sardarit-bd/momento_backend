<?php

namespace App\Http\Resources\TGC;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => data_get($this->resource, 'result.id') ?? data_get($this->resource, 'id'),
            'name'         => data_get($this->resource, 'result.name') ?? data_get($this->resource, 'name'),
            'company'      => data_get($this->resource, 'result.company') ?? data_get($this->resource, 'company'),
            'address1'     => data_get($this->resource, 'result.address1') ?? data_get($this->resource, 'address1'),
            'address2'     => data_get($this->resource, 'result.address2') ?? data_get($this->resource, 'address2'),
            'city'         => data_get($this->resource, 'result.city') ?? data_get($this->resource, 'city'),
            'state'        => data_get($this->resource, 'result.state') ?? data_get($this->resource, 'state'),
            'postal_code'  => data_get($this->resource, 'result.postal_code') ?? data_get($this->resource, 'postal_code'),
            'country'      => data_get($this->resource, 'result.country') ?? data_get($this->resource, 'country'),
            'phone_number' => data_get($this->resource, 'result.phone_number') ?? data_get($this->resource, 'phone_number'),
        ];
    }
}