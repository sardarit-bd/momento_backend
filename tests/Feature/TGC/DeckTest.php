<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TGCFakeResponseFactory;

it('creates a deck', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/decks' => Http::response(TGCFakeResponseFactory::deck(), 201),
    ]);

    $this->postJson('/api/tgc/games/game_1/decks', ['name' => 'Main Deck', 'card_count' => 54])
        ->assertStatus(201)
        ->assertJsonPath('data.id', 'deck_1');
});

it('validates deck payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/games/game_1/decks', [])->assertStatus(422);
});

it('returns 502 on tgc deck error', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/decks' => Http::response(TGCFakeResponseFactory::error('TGC deck failed'), 500),
    ]);

    $this->postJson('/api/tgc/games/game_1/decks', ['name' => 'Main Deck'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'TGC deck failed');
});

it('requires auth for deck endpoint', function () {
    $this->postJson('/api/tgc/games/game_1/decks', ['name' => 'Main Deck'])->assertStatus(401);
});
