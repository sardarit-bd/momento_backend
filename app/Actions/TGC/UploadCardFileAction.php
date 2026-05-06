<?php

namespace App\Actions\TGC;

use App\DTOs\TGC\UploadFileDTO;
use App\Exceptions\TGC\TGCFileUploadException;
use App\Services\TGC\TGCService;

class UploadCardFileAction
{
    public function __construct(private readonly TGCService $service)
    {
    }

    public function handle(UploadFileDTO $dto): array
    {
        if (! in_array($dto->mimeType, ['image/png', 'image/jpeg'], true)) {
            throw new TGCFileUploadException('Only PNG and JPEG files are allowed');
        }

        if (filesize($dto->filePath) > 20 * 1024 * 1024) {
            throw new TGCFileUploadException('File size must not exceed 20MB');
        }

        return $this->service->uploadFile($dto);
    }
}
