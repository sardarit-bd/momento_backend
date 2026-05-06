<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class AddToCartDTO
{
    public function __construct(
        public string $cartId,
        public string $skuId,
        public int $quantity,
    ) {
        if (trim($this->cartId) === '' || trim($this->skuId) === '' || $this->quantity < 1) {
            throw new InvalidArgumentException('cart_id, sku_id and quantity are required');
        }
    }
    
    public static function fromRequest(Request $request, string $cartId): self
    {
        return new self(
            cartId: $cartId,
            skuId: (string) $request->input('sku_id'),
            quantity: (int) $request->input('quantity', 1),
        );
    }
}
