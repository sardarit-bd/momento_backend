<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\TGCFakeResponseFactory;

it('uploads a file', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(['result' => ['id' => 'sess_123', 'user_id' => 'user_1']], 200),
        '*/designer*' => Http::response(['result' => ['id' => 'designer_1']], 200),
        '*/file' => Http::response([
            'id' => 'file_1',
            'name' => 'card-front.png',
            'folder_id' => 'folder_1',
            'has_proofed' => true,
        ], 201),
    ]);

    $this->post('/api/tgc/files', [
        'name' => 'card-front.png',
        'folder_id' => 'folder_1',
        'file' => UploadedFile::fake()->image('card-front.png'),
        'has_proofed' => 1,
    ])->assertStatus(201)
        ->assertJsonPath('data.id', 'file_1')
        ->assertJsonPath('data.folder_id', 'folder_1');
});

it('validates upload file payload', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    $this->postJson('/api/tgc/files', [])->assertStatus(422);
});

it('returns JSON validation errors for form-data style requests', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    // Using post() (not postJson) mimics form-data requests without JSON headers.
    $this->post('/api/tgc/files', [])->assertStatus(422)->assertJsonStructure([
        'message',
        'errors',
    ]);
});

it('returns 502 on tgc file upload error', function () {
    $this->actingAs(User::factory()->make(['id' => 1]), 'api');

    Http::fake([
        '*/session' => Http::response(['result' => ['id' => 'sess_123', 'user_id' => 'user_1']], 200),
        '*/designer*' => Http::response(['result' => ['id' => 'designer_1']], 200),
        '*/file' => Http::response(TGCFakeResponseFactory::error('TGC file upload failed'), 500),
    ]);

    $this->post('/api/tgc/files', [
        'name' => 'card-front.png',
        'folder_id' => 'folder_1',
        'file' => UploadedFile::fake()->image('card-front.png'),
        'has_proofed' => 1,
    ])->assertStatus(502)->assertJsonPath('message', 'TGC file upload failed');
});

it('requires auth for file upload endpoint', function () {
    $this->postJson('/api/tgc/files', [])->assertStatus(401);
});
