<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CartPriceResolver;
use Illuminate\Http\Request;

class CartPricingController extends Controller
{
    public function __construct(private CartPriceResolver $resolver) {}

    /**
     * POST /api/cart/price
     *
     * Given cart line identifiers (product + qty + optional package),
     * returns authoritative unit prices and totals. This is a read-only
     * preview endpoint used to render the checkout page — the checkout
     * session creation endpoint must independently re-resolve prices
     * using the same CartPriceResolver rather than trusting this
     * response's numbers if they're echoed back by the client.
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.package_slug' => ['nullable', 'string'],
            'items.*.has_joker' => ['sometimes', 'boolean'],
        ]);

        $priced = $this->resolver->priceCart($validated['items']);

        return response()->json([
            'items' => $priced['lines'],
            'subtotal' => $priced['subtotal'],
            'tax' => $priced['tax'],
            'total' => $priced['total'],
        ]);
    }
}
