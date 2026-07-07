<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('cart pricing preview', function () {
    it('includes joker breakdown and multiplies the addon by qty', function () {
        $product = Product::create([
            'name' => 'Momento Portrait Deck',
            'slug' => 'momento-portrait-deck',
            'type' => 'customizable',
            'price' => 59.00,
            'offer_price' => 0.00,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/cart/price', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                    'has_joker' => true,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.has_joker', true);
        $response->assertJsonPath('items.0.base_unit_price', 59.0);
        $response->assertJsonPath('items.0.joker_addon', 7.0);
        $response->assertJsonPath('items.0.unit_price', 66.0);
        $response->assertJsonPath('items.0.line_total', 132.0);
        $response->assertJsonPath('subtotal', 132.0);
        $response->assertJsonPath('tax', 10.56);
        $response->assertJsonPath('total', 142.56);
    });
});
