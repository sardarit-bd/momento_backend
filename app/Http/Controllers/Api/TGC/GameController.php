<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\CreateGameAction;
use App\DTOs\TGC\CreateGameDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\CreateGameRequest;
use App\Http\Resources\TGC\GameResource;

class GameController extends Controller
{
    public function __construct(private readonly CreateGameAction $createGameAction) {}

    public function store(CreateGameRequest $request)
    {
        $payload = $this->createGameAction->handle(CreateGameDTO::fromRequest($request));

        return (new GameResource($payload))->response()->setStatusCode(201);
    }
}
