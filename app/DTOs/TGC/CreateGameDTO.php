<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class CreateGameDTO
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('name is required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: (string) $request->string('name'),
            description: $request->filled('description') ? (string) $request->string('description') : null,
        );
    }
}
