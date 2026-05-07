<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class UploadFileDTO
{
    public function __construct(
        public string $deckId,
        public string $folderId,
        public string $filePath,
        public string $fileName,
        public string $mimeType,
        public string $label = 'card-image',
    ) {
        if (trim($this->deckId) === '' || trim($this->filePath) === '' || trim($this->fileName) === '' || trim($this->mimeType) === '') {
            throw new InvalidArgumentException('deck_id and file data are required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        $file = $request->file('file') ?? $request->file('face_image');

        return new self(
            deckId: (string) $request->route('deckId', $request->input('deck_id')),
            filePath: (string) $file?->getRealPath(),
            fileName: (string) $file?->getClientOriginalName(),
            mimeType: (string) $file?->getMimeType(),
            label: (string) $request->input('label', 'card-image'),
        );
    }
}
