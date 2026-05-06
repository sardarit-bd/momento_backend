<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\TGCFakeResponseFactory;

it('creates a card', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/files' => Http::sequence()->push(TGCFakeResponseFactory::file('file_face'), 201)->push(TGCFakeResponseFactory::file('file_back'), 201),
        '*/cards' => Http::response(TGCFakeResponseFactory::card(), 201),
    ]);

    $this->post('/api/tgc/decks/deck_1/cards', [
        'deck_id' => 'deck_1',
        'name' => 'Ace',
        'face_image' => UploadedFile::fake()->image('face.png'),
        'back_image' => UploadedFile::fake()->image('back.jpeg'),
    ])->assertStatus(201)->assertJsonPath('data.id', 'card_1');
});

it('proofs a card', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/cards/card_1/proof' => Http::response(TGCFakeResponseFactory::proofedCard(), 200),
    ]);

    $this->putJson('/api/tgc/cards/card_1/proof', ['card' => ['back_id' => 'file_back']])
        ->assertStatus(200)
        ->assertJsonPath('data.has_proofed_face', true);
});

it('creates a card from existing face id', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/card' => Http::response(TGCFakeResponseFactory::proofedCard(), 201),
    ]);

    $this->postJson('/api/tgc/cards', [
        'name' => 'Ace',
        'deck_id' => 'deck_1',
        'face_id' => 'file_face',
        'has_proofed_face' => 1,
        'has_proofed_back' => 1,
    ])->assertStatus(201)
        ->assertJsonPath('data.deck_id', 'deck_1')
        ->assertJsonPath('data.has_proofed_face', true);
});

it('validates card payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/decks/deck_1/cards', [])->assertStatus(422);
});

it('validates card-from-face payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/cards', [])->assertStatus(422);
});

it('returns 502 on tgc card error', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(TGCFakeResponseFactory::session(), 200),
        '*/files' => Http::response(TGCFakeResponseFactory::file('file_face'), 201),
        '*/cards' => Http::response(TGCFakeResponseFactory::error('TGC card failed'), 500),
    ]);

    $this->post('/api/tgc/decks/deck_1/cards', [
        'deck_id' => 'deck_1',
        'name' => 'Ace',
        'face_image' => UploadedFile::fake()->image('face.png'),
    ])->assertStatus(502)->assertJsonPath('message', 'TGC card failed');
});

it('requires auth for card endpoint', function () {
    $this->postJson('/api/tgc/decks/deck_1/cards', [])->assertStatus(401);
});
