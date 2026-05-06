<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TGCFakeResponseFactory;

it('creates a game', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/games' => Http::response(TGCFakeResponseFactory::game(), 201),
    ]);

    $response = $this->postJson('/api/tgc/games', ['name' => 'My Game', 'description' => 'Demo']);

    $response->assertStatus(201)->assertJsonPath('data.id', 'game_1');
});

it('validates game payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/games', [])->assertStatus(422);
});

it('returns 502 on tgc game error', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/games' => Http::response(TGCFakeResponseFactory::error('TGC game failed'), 500),
    ]);

    $this->postJson('/api/tgc/games', ['name' => 'My Game'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'TGC game failed');
});

it('requires auth for game endpoint', function () {
    $this->postJson('/api/tgc/games', ['name' => 'My Game'])->assertStatus(401);
});
