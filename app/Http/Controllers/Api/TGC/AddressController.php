<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\CreateAddressAction;
use App\DTOs\TGC\CreateAddressDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\CreateAddressRequest;
use App\Http\Resources\TGC\AddressResource;
use App\Services\TGC\TGCService;

class AddressController extends Controller
{
    public function __construct(private readonly CreateAddressAction $createAddressAction)
    {
    }

    public function store(CreateAddressRequest $request)
    {
        $payload = $this->createAddressAction->handle(CreateAddressDTO::fromRequest($request));

        return (new AddressResource($payload))->response()->setStatusCode(201);
    }

    public function show(string $addressId)
    {
        $payload = app(TGCService::class)->getAddress($addressId);
        return (new AddressResource($payload))->response();
    }
}