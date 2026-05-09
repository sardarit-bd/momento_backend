<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateAddressDTO
{
    public function __construct(
        public string $name,
        public string $address1,
        public string $city,
        public string $state,
        public string $postalCode,
        public string $country,
        public string $phoneNumber,
        public ?string $company = null,
        public ?string $address2 = null,
    ) {
        if (
            trim($this->name) === '' ||
            trim($this->address1) === '' ||
            trim($this->city) === '' ||
            trim($this->state) === '' ||
            trim($this->postalCode) === '' ||
            trim($this->country) === '' ||
            trim($this->phoneNumber) === ''
        ) {
            throw new InvalidArgumentException('All required address fields must be provided');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: (string) $request->string('name'),
            address1: (string) $request->string('address1'),
            city: (string) $request->string('city'),
            state: (string) $request->string('state'),
            postalCode: (string) $request->string('postal_code'),
            country: (string) $request->string('country'),
            phoneNumber: (string) $request->string('phone_number'),
            company: $request->filled('company') ? (string) $request->string('company') : null,
            address2: $request->filled('address2') ? (string) $request->string('address2') : null,
        );
    }
}