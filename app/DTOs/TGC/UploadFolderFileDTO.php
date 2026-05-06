<?php

namespace App\DTOs\TGC;

use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class UploadFolderFileDTO
{
    public function __construct(
        public string $name,
        public string $folderId,
        public string $filePath,
        public string $fileName,
        public string $mimeType,
        public int $hasProofed = 1,
    ) {
        if (
            trim($this->name) === '' ||
            trim($this->folderId) === '' ||
            trim($this->filePath) === '' ||
            trim($this->fileName) === '' ||
            trim($this->mimeType) === ''
        ) {
            throw new InvalidArgumentException('name, folder_id and file data are required');
        }
    }

    public static function fromRequest(Request $request): self
    {
        $file = $request->file('file');

        return new self(
            name: (string) $request->string('name'),
            folderId: (string) $request->input('folder_id'),
            filePath: (string) $file?->getRealPath(),
            fileName: (string) $file?->getClientOriginalName(),
            mimeType: (string) $file?->getMimeType(),
            hasProofed: (int) $request->input('has_proofed', 1),
        );
    }
}
