<?php

namespace App\Http\Controllers\Api\TGC;

use App\Actions\TGC\CreateCardAction;
use App\Actions\TGC\CreateCardFromFaceAction;
use App\Actions\TGC\ProofCardAction;
use App\Actions\TGC\UploadCardFileAction;
use App\DTOs\TGC\CreateCardDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\ProofCardDTO;
use App\DTOs\TGC\UploadFileDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TGC\CreateCardRequest;
use App\Http\Requests\TGC\CreateCardFromFaceRequest;
use App\Http\Requests\TGC\ProofCardRequest;
use App\Http\Resources\TGC\CardResource;
use Log;

class CardController extends Controller
{
    public function __construct(
        private readonly UploadCardFileAction $uploadCardFileAction,
        private readonly CreateCardAction $createCardAction,
        private readonly CreateCardFromFaceAction $createCardFromFaceAction,
        private readonly ProofCardAction $proofCardAction,
    ) {
    }

    public function store(CreateCardRequest $request, string $deckId)
    {
        $faceFile = $request->file('face_image');
        $face = $this->uploadCardFileAction->handle(new UploadFileDTO(
            deckId: $deckId,
            folderId: (string) $request->input('folder_id'),
            filePath: (string) $faceFile?->getRealPath(),
            fileName: (string) $faceFile?->getClientOriginalName(),
            mimeType: (string) $faceFile?->getMimeType(),
            label: 'face-image',
        ));

        $faceFileId = (string) data_get($face, 'result.id');

        $backFileId = null;
        if ($request->hasFile('back_image')) {
            $backFile = $request->file('back_image');
            $back = $this->uploadCardFileAction->handle(new UploadFileDTO(
                deckId: $deckId,
                folderId: (string) $request->input('folder_id'),
                filePath: (string) $backFile?->getRealPath(),
                fileName: (string) $backFile?->getClientOriginalName(),
                mimeType: (string) $backFile?->getMimeType(),
                label: 'back-image',
            ));
            $backFileId = (string) data_get($back, 'result.id');
        }

        $payload = $this->createCardAction->handle(new CreateCardDTO(
            deckId: $deckId,
            name: (string) $request->string('name'),
            faceFileId: $faceFileId,
            backFileId: $backFileId ?: null,
            hasProofedBack: $backFileId ? 1 : 0,
        ));

        return (new CardResource($payload))->response()->setStatusCode(201);
    }

    public function proof(ProofCardRequest $request, string $cardId)
    {
        $request->merge(['card_id' => $cardId]);

        $payload = $this->proofCardAction->handle(ProofCardDTO::fromRequest($request));

        return (new CardResource($payload))->response()->setStatusCode(200);
    }

    public function storeFromFace(CreateCardFromFaceRequest $request)
    {
        $payload = $this->createCardFromFaceAction->handle(CreateCardFromFaceDTO::fromRequest($request));

        return (new CardResource($payload))->response()->setStatusCode(201);
    }
}
