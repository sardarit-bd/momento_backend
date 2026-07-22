<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\CreateFolderAction;
use App\DTOs\TGC\CreateFolderDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\CreateFolderRequest;
use App\Http\Resources\TGC\FolderResource;

class FolderController extends Controller
{
    public function __construct(private readonly CreateFolderAction $createFolderAction) {}

    public function store(CreateFolderRequest $request)
    {
        $payload = $this->createFolderAction->handle(CreateFolderDTO::fromRequest($request));

        return (new FolderResource($payload))->response()->setStatusCode(201);
    }
}
