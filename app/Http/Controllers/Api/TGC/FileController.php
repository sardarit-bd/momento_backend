<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\UploadFileAction;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\UploadFileRequest;
use App\Http\Resources\TGC\FileResource;

class FileController extends Controller
{
    public function __construct(private readonly UploadFileAction $uploadFileAction) {}

    public function store(UploadFileRequest $request)
    {
        $payload = $this->uploadFileAction->handle(UploadFolderFileDTO::fromRequest($request));

        return (new FileResource($payload))->response()->setStatusCode(201);
    }
}
