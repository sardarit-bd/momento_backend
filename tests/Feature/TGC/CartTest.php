<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TGCFakeResponseFactory;

it('creates a cart', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/cart' => Http::response(TGCFakeResponseFactory::cart(), 201),
    ]);

    $this->postJson('/api/tgc/carts')->assertStatus(201)->assertJsonPath('data.id', 'cart_1');
});

it('adds item to cart', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/carts/cart_1/items' => Http::response(TGCFakeResponseFactory::cartWithItem(), 200),
    ]);

    $this->postJson('/api/tgc/carts/cart_1/items', [
        'cart_id' => 'cart_1',
        'sku_id' => 'sku_1',
        'quantity' => 2,
    ])->assertStatus(200)->assertJsonPath('data.id', 'cart_1');
});

it('validates cart payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/carts/cart_1/items', [])->assertStatus(422);
});

it('returns 502 on tgc cart error', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/carts/cart_1/items' => Http::response(TGCFakeResponseFactory::error('TGC cart failed'), 500),
    ]);

    $this->postJson('/api/tgc/carts/cart_1/items', [
        'cart_id' => 'cart_1',
        'sku_id' => 'sku_1',
        'quantity' => 2,
    ])->assertStatus(502)->assertJsonPath('message', 'TGC cart failed');
});

it('requires auth for cart endpoint', function () {
    $this->postJson('/api/tgc/carts')->assertStatus(401);
});
