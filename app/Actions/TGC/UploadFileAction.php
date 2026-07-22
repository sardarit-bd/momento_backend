<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\UploadFolderFileDTO;
use App\Exceptions\TGC\TGCFileUploadException;
use App\Services\TGC\TGCService;

class UploadFileAction
{
    public function __construct(private readonly TGCService $service) {}

    public function handle(UploadFolderFileDTO $dto): array
    {
        if (filesize($dto->filePath) > 20 * 1024 * 1024) {
            throw new TGCFileUploadException('File size must not exceed 20MB');
        }

        return $this->service->uploadFolderFile($dto);
    }
}
