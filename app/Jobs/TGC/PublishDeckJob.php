<?php

namespace App\Jobs\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\UpdateTuckBoxDTO;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\Services\TGC\CardMergeService;
use App\Services\TGC\TGCService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublishDeckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function __construct(
        private readonly string $jobId,
        private readonly string $deckId,
        private readonly string $folderId,
        private readonly string $cartId,
        private readonly string $skuId,
        private readonly string $tuckboxId,
        private readonly array  $cardStoragePaths,
        private readonly string $tempDir,
        private readonly string $boxAbsolutePath,
    ) {}

    public function handle(TGCService $tgc): void
    {
        $this->setStatus('running', 'Starting card uploads...');
        $total = count($this->cardStoragePaths);

        // ── Step 1: Upload all 54 cards ──────────────────────────────────
        foreach ($this->cardStoragePaths as $index => $storagePath) {
            $cardNumber = $index + 1;
            $cardName   = sprintf('Card %02d', $cardNumber);

            try {
                $absolutePath = Storage::disk('local')->path($storagePath);
                $mimeType     = mime_content_type($absolutePath) ?: 'image/jpeg';

                $fileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                    name:       $cardName,
                    folderId:   $this->folderId,
                    filePath:   $absolutePath,
                    fileName:   basename($absolutePath),
                    mimeType:   $mimeType,
                    hasProofed: false,
                ));

                $faceFileId = data_get($fileResponse, 'id')
                    ?? throw new \RuntimeException("No file ID for card {$cardNumber}");

                $tgc->createCardFromFace(new CreateCardFromFaceDTO(
                    name:           $cardName,
                    deckId:         $this->deckId,
                    faceId:         $faceFileId,
                    hasProofedFace: false,
                    hasProofedBack: false,
                ));

                $this->setStatus('running', "Uploaded {$cardNumber}/{$total} cards.", [
                    'uploaded' => $cardNumber,
                    'total'    => $total,
                ]);

            } catch (Throwable $e) {
                $this->setStatus('failed', "Card {$cardNumber} failed: " . $e->getMessage());
                $this->cleanup();
                return;
            }

            if ($cardNumber < $total) {
                sleep(1);
            }
        }

        // ── Step 2: Upload box image → attach to tuckbox ─────────────────
        try {
            $this->setStatus('running', 'Uploading tuckbox image...');

            $mimeType = mime_content_type($this->boxAbsolutePath) ?: 'image/png';

            $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                name:       'tuckbox-outside',
                folderId:   $this->folderId,
                filePath:   $this->boxAbsolutePath,
                fileName:   basename($this->boxAbsolutePath),
                mimeType:   $mimeType,
                hasProofed: false,
            ));

            $boxFileId = data_get($boxFileResponse, 'id')
                ?? throw new \RuntimeException('No file ID for tuckbox image');

            $tgc->updateTuckBox(new UpdateTuckBoxDTO(
                tuckboxId:          $this->tuckboxId,
                outsideId:          $boxFileId,
                hasProofedOutside:  false,
            ));

            $this->setStatus('running', 'Tuckbox image attached.');

        } catch (Throwable $e) {
            $this->setStatus('failed', 'Tuckbox upload failed: ' . $e->getMessage());
            $this->cleanup();
            return;
        }

        // ── Step 3: Add to cart ───────────────────────────────────────────
        try {
            $this->setStatus('running', 'Adding deck to cart...');

            $tgc->addSkuToCart(new AddToCartDTO(
                cartId:   $this->cartId,
                skuId:    $this->skuId,
                quantity: 1,
            ));

            $this->setStatus('completed', 'Deck published and added to cart.', [
                'uploaded' => $total,
                'total'    => $total,
                'cart_id'  => $this->cartId,
            ]);

        } catch (Throwable $e) {
            $this->setStatus('failed', 'Cart step failed: ' . $e->getMessage());
        }

        $this->cleanup();
    }

    public function failed(Throwable $e): void
    {
        $this->setStatus('failed', 'Job failed unexpectedly: ' . $e->getMessage());
        $this->cleanup();
    }

    private function setStatus(string $status, string $message, array $extra = []): void
    {
        Cache::put("tgc_job:{$this->jobId}", array_merge([
            'status'     => $status,
            'message'    => $message,
            'job_id'     => $this->jobId,
            'updated_at' => now()->toISOString(),
        ], $extra), now()->addHours(2));
    }

    private function cleanup(): void
    {
        app(CardMergeService::class)->cleanup($this->tempDir);

        // Also clean up box temp file
        if (file_exists($this->boxAbsolutePath)) {
            @unlink($this->boxAbsolutePath);
        }
    }
}