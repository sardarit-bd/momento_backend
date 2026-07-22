<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\CreateDeckAction;
use App\DTOs\TGC\CreateDeckDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\CreateDeckRequest;
use App\Http\Resources\TGC\DeckResource;

class DeckController extends Controller
{
    public function __construct(private readonly CreateDeckAction $createDeckAction) {}

    public function store(CreateDeckRequest $request, string $gameId)
    {
        $request->merge(['game_id' => $gameId]);

        $payload = $this->createDeckAction->handle(CreateDeckDTO::fromRequest($request));

        return (new DeckResource($payload))->response()->setStatusCode(201);
    }

    public function storeDirect(CreateDeckRequest $request)
    {
        $payload = $this->createDeckAction->handle(CreateDeckDTO::fromRequest($request));

        return (new DeckResource($payload))->response()->setStatusCode(201);
    }
}
