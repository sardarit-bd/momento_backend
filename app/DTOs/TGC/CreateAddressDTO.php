<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateAddressDTO
{
    private function __construct(
        public string $name,
        public string $address1,
        public string $city,
        public string $state,
        public string $postalCode,
        public string $country,
        public string $phoneNumber,
        public ?string $company = null,
        public ?string $address2 = null,
    ) {}

    // ── Primary factory — use this everywhere ────────────────────────────
    public static function make(
        string $name,
        string $address1,
        string $city,
        string $state,
        string $postalCode,
        string $country,
        string $phoneNumber,
        ?string $company = null,
        ?string $address2 = null,
    ): self {
        $required = compact('name', 'address1', 'city', 'state', 'postalCode', 'country', 'phoneNumber');

        foreach ($required as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Address field [{$field}] must not be empty.");
            }
        }

        return new self(
            name: self::cap($name),
            address1: self::cap($address1),
            city: self::cap($city),
            state: self::cap($state),
            postalCode: self::cap($postalCode, 20),
            country: strtoupper(trim($country)),
            phoneNumber: trim($phoneNumber),
            company: $company ? self::cap($company) : null,
            address2: $address2 ? self::cap($address2) : null,
        );
    }

    // ── Request factory ──────────────────────────────────────────────────
    public static function fromRequest(Request $request): self
    {
        return self::make(
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

    // ── TGC hard limit: 35 chars on most fields, 20 on postal ───────────
    private static function cap(string $value, int $max = 35): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
