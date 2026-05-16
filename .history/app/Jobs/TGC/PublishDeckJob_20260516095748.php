<?php

namespace App\Jobs\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateAddressDTO;
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
use Log;
use Throwable;

class PublishDeckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function __construct(
        private readonly int $orderId,
    ) {}

    public function handle(TGCService $tgc): void
    {
        Log::info('PublishDeckJob started', ['order_id' => $this->orderId]);

        $order = null;
        try {
            $order = \App\Models\Order::with([
                'orderItems.cards',
                'shippingInformation',
            ])->findOrFail($this->orderId);
            Log::info('Order loaded successfully', ['order_id' => $order->id]);
        } catch (Throwable $e) {
            Log::error('PublishDeckJob crashed', [
                'order_id' => $this->orderId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return;
        }

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $username = $order->name . '-' . time();

        Log::info('Job variables set', ['jobId' => $jobId, 'username' => $username]);

        Log::info('Calling setStatus...');
        $this->setStatus($jobId, 'running', 'Starting TGC publish...');
        Log::info('setStatus done, calling createGame...');

        // ── Step 1: Create Game ──────────────────────────────────────────
        try {
            Log::info('createGame calling...');
            $game   = $tgc->createGame(new \App\DTOs\TGC\CreateGameDTO(name: $username));
            Log::info('createGame response', ['game' => $game]);
            $gameId = data_get($game, 'result.id')
                ?? throw new \RuntimeException('TGC game creation failed: no id');
            $skuId  = data_get($game, 'result.sku_id')
                ?? throw new \RuntimeException('TGC game creation failed: no sku_id');
            $this->setStatus($jobId, 'running', 'Game created.');
            Log::info('TGC Game created', ['game_id' => $gameId, 'sku_id' => $skuId]);
        } catch (Throwable $e) {
            Log::error('Game creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setStatus($jobId, 'failed', 'Game creation failed: ' . $e->getMessage());
            return;
        }

        // ── Step 2: Create Folder ────────────────────────────────────────
        try {
            $folder   = $tgc->createFolder(new \App\DTOs\TGC\CreateFolderDTO(name: $username . '-folder'));
            $folderId = data_get($folder, 'result.id')
                ?? throw new \RuntimeException('TGC folder creation failed: no id');
            $this->setStatus($jobId, 'running', 'Folder created.');
            Log::info('TGC Folder created', ['folder_id' => $folderId]);
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Folder creation failed: ' . $e->getMessage());
            return;
        }

        // ── Step 3: Create Deck ──────────────────────────────────────────
        try {
            $deck   = $tgc->createDeck(new \App\DTOs\TGC\CreateDeckDTO(
                gameId:         $gameId,
                name:           $username . '-deck',
                identity:       'PokerDeck',
                hasProofedBack: true,
                backId:         '65E06A22-4F4A-11F1-99A9-0EB33FE31C9A',
            ));
            $deckId = data_get($deck, 'result.id')
                ?? throw new \RuntimeException('TGC deck creation failed: no id');
            $this->setStatus($jobId, 'running', 'Deck created.');

            Log::info('TGC Deck created', ['deck_id' => $deckId]);

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Deck creation failed: ' . $e->getMessage());
            return;
        }

        // ── Step 4: Upload box image ─────────────────────────────────────
        // try {
        //     $this->setStatus($jobId, 'running', 'Uploading tuckbox image...');

        //     $customizedFile = $order->customized_file;
        //     $tuckboxPath    = null;

        //     if (is_array($customizedFile)) {
        //         foreach ($customizedFile as $path) {
        //             if (str_contains($path, 'tuckbox')) {
        //                 $tuckboxPath = $path;
        //                 break;
        //             }
        //         }
        //     } elseif (is_string($customizedFile) && str_contains($customizedFile, 'tuckbox')) {
        //         $tuckboxPath = $customizedFile;
        //     }

        //     if (!$tuckboxPath) {
        //         throw new \RuntimeException('Tuckbox image not found in order');
        //     }

        //     $absoluteBoxPath = Storage::disk('public')->path($tuckboxPath);
        //     $mimeType        = mime_content_type($absoluteBoxPath) ?: 'image/png';

        //     $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
        //         name:       'tuckbox-outside',
        //         folderId:   $folderId,
        //         filePath:   $absoluteBoxPath,
        //         fileName:   basename($absoluteBoxPath),
        //         mimeType:   $mimeType,
        //         hasProofed: true,
        //     ));

        //     $boxFileId = data_get($boxFileResponse, 'result.id')
        //         ?? throw new \RuntimeException('No file ID for tuckbox image');

        //     Log::info('Tuckbox image uploaded', ['box_file_id' => $boxFileId]);

        // } catch (Throwable $e) {
        //     $this->setStatus($jobId, 'failed', 'Tuckbox image upload failed: ' . $e->getMessage());
        //     $this->cleanup($jobId);
        //     return;
        // }

                try {
            $this->setStatus($jobId, 'running', 'Compositing tuckbox image...');

            $characterBlobs = [];
foreach ($order->orderItems as $item) {
    foreach ($item->cards as $card) {
        if ($card->card_type === 'deck' && $card->character_blob) {
            $characterBlobs[$card->slot_name] = $card->character_blob;
        }
    }
}
$characterBlobs = array_values($characterBlobs);

            Log::info('Compositing tuckbox', ['character_count' => count($characterBlobs)]);

            $compositor  = app(\App\Services\TGC\TuckBoxCompositeService::class);
            $tuckboxBlob = $compositor->composite($characterBlobs);

            // Save to temp file
            $tuckboxTempPath = 'temp/' . $jobId . '/tuckbox_composite.png';
            Storage::disk('local')->put($tuckboxTempPath, $tuckboxBlob);
            $absoluteBoxPath = Storage::disk('local')->path($tuckboxTempPath);

            Log::info('Tuckbox composited', ['size' => strlen($tuckboxBlob)]);

            $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                name:       'tuckbox-outside',
                folderId:   $folderId,
                filePath:   $absoluteBoxPath,
                fileName:   'tuckbox_composite.png',
                mimeType:   'image/png',
                hasProofed: true,
            ));

            $boxFileId = data_get($boxFileResponse, 'result.id')
                ?? throw new \RuntimeException('No file ID for tuckbox image');

            Log::info('Tuckbox uploaded', ['box_file_id' => $boxFileId]);

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Tuckbox composite failed: ' . $e->getMessage());
            Log::error('Tuckbox composite failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->cleanup($jobId);
            return;
        }

        // ── Step 5: Create TuckBox with outside_id ───────────────────────
        try {
            $tuckbox   = $tgc->createTuckBox(new \App\DTOs\TGC\CreateTuckBoxDTO(
                name:             $username . '-box',
                gameId:           $gameId,
                outsideId:        $boxFileId,
                hasProofedOutside: true,
            ));
            $tuckboxId = data_get($tuckbox, 'result.id')
                ?? throw new \RuntimeException('TGC tuckbox creation failed: no id');
            $this->setStatus($jobId, 'running', 'TuckBox created.');
            Log::info('TGC TuckBox created', ['tuckbox_id' => $tuckboxId]);
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'TuckBox creation failed: ' . $e->getMessage());
            return;
        }


                // ── Step 6: Merge & Upload 54 cards ─────────────────────────────
        try {
            Log::info('Loading custom cards from DB...');
            // Get custom card blobs from order_item_cards
            $customCards = [];
            foreach ($order->orderItems as $item) {
                foreach ($item->cards as $card) {
                    if ($card->card_type === 'deck' && $card->image_blob) {
                        $customCards[] = $card;
                    }
                }
            }

            Log::info('Custom cards loaded', ['count' => count($customCards)]);

            $tempDir  = 'temp/' . $jobId;
            
            // 1. Define the exact 54 cards in the strict sequence expected by TGC
            $deckOrder = [
                'Clubs_Ace.png', 'Clubs_Number_2.png', 'Clubs_Number_3.png', 'Clubs_Number_4.png', 'Clubs_Number_5.png', 'Clubs_Number_6.png', 'Clubs_Number_7.png', 'Clubs_Number_8.png', 'Clubs_Number_9.png', 'Clubs_Number_10.png', 'Clubs_Face_Jack.png', 'Clubs_Face_Queen.png', 'Clubs_Face_King.png',
                'Diamonds_Ace.png', 'Diamonds_Number_2.png', 'Diamonds_Number_3.png', 'Diamonds_Number_4.png', 'Diamonds_Number_5.png', 'Diamonds_Number_6.png', 'Diamonds_Number_7.png', 'Diamonds_Number_8.png', 'Diamonds_Number_9.png', 'Diamonds_Number_10.png', 'Diamonds_Face_Jack.png', 'Diamonds_Face_Queen.png', 'Diamonds_Face_King.png',
                'Hearts_Ace.png', 'Hearts_Number_2.png', 'Hearts_Number_3.png', 'Hearts_Number_4.png', 'Hearts_Number_5.png', 'Hearts_Number_6.png', 'Hearts_Number_7.png', 'Hearts_Number_8.png', 'Hearts_Number_9.png', 'Hearts_Number_10.png', 'Hearts_Face_Jack.png', 'Hearts_Face_Queen.png', 'Hearts_Face_King.png',
                'Spades_Ace.png', 'Spades_Number_2.png', 'Spades_Number_3.png', 'Spades_Number_4.png', 'Spades_Number_5.png', 'Spades_Number_6.png', 'Spades_Number_7.png', 'Spades_Number_8.png', 'Spades_Number_9.png', 'Spades_Number_10.png', 'Spades_Face_Jack.png', 'Spades_Face_Queen.png', 'Spades_Face_King.png',
                'Joker_1.png', 'Joker_2.png'
            ];

            $cardPaths = []; // We will build the absolute paths directly

            // 2. Iterate over the 54 expected slots
            foreach ($deckOrder as $filename) {
                $targetPath = $tempDir . '/' . $filename;
                $isCustom = false;

                // 3. Check if any customized card matches this exact suit and rank
                foreach ($customCards as $card) {
                    // card->rank holds the slotName from FinalProduct (e.g. "Clubs_Ace")
                    $slotName = $card->slot_name ?? null;
                    if (!$slotName) continue;

                    $expectedFilename = $slotName . '.png'; // e.g. "Clubs_Ace.png"

                    if ($expectedFilename === $filename) {
                        Log::info('Replacing default card with custom', ['target' => $filename]);
                        $resizedBlob = $this->resizeImageTo825x1125($card->image_blob);
                        Storage::disk('local')->put($targetPath, $resizedBlob);
                        $isCustom = true;
                        break;
                    }
                }

                // 4. If no custom card replaces it, copy the default system card
                if (!$isCustom) {
                    $defaultPath = 'cards/' . $filename;
                    
                    if (!Storage::disk('local')->exists($defaultPath)) {
                        throw new \RuntimeException("Default system card missing: {$defaultPath}");
                    }
                    
                    Storage::disk('local')->copy($defaultPath, $targetPath);
                }

                $cardPaths[] = Storage::disk('local')->path($targetPath);
            }

            $total = count($cardPaths);
            Log::info('Temp files prepared in exact sequence', ['total' => $total]);


            foreach ($cardPaths as $index => $absolutePath) {
                $cardNumber = $index + 1;
                $cardName   = sprintf('Card %02d', $cardNumber);
                $mimeType   = mime_content_type($absolutePath) ?: 'image/jpeg';

                Log::info('Uploading card', [
                    'card_number' => $cardNumber, 
                    'file'        => basename($absolutePath), 
                    'mime'        => $mimeType
                ]);

                $fileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                    name:       $cardName,
                    folderId:   $folderId,
                    filePath:   $absolutePath,
                    fileName:   basename($absolutePath),
                    mimeType:   $mimeType,
                    hasProofed: true,
                ));

                $faceFileId = data_get($fileResponse, 'result.id')
                    ?? throw new \RuntimeException("No file ID for card {$cardNumber}");

                $tgc->createCardFromFace(new CreateCardFromFaceDTO(
                    name:           $cardName,
                    deckId:         $deckId,
                    faceId:         $faceFileId,
                    hasProofedFace: true,
                    hasProofedBack: true,
                ));

                $this->setStatus($jobId, 'running', "Uploaded {$cardNumber}/{$total} cards.", [
                    'uploaded' => $cardNumber,
                    'total'    => $total,
                ]);

                if ($cardNumber < $total) sleep(1);
                Log::info("Card uploaded", ['card_number' => $cardNumber, 'face_file_id' => $faceFileId]);
                
            }

        } catch (Throwable $e) {
            Log::error('Card upload failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setStatus($jobId, 'failed', 'Card upload failed: ' . $e->getMessage());
            $this->cleanup($jobId);
            return;
        }

        // // ── Step 7: Upload tuckbox image ─────────────────────────────────
        // try {
        //     $this->setStatus($jobId, 'running', 'Uploading tuckbox image...');

        //     $customizedFile = $order->customized_file;
        //     $tuckboxPath    = null;

        //     if (is_array($customizedFile)) {
        //         foreach ($customizedFile as $path) {
        //             if (str_contains($path, 'tuckbox')) {
        //                 $tuckboxPath = $path;
        //                 break;
        //             }
        //         }
        //     } elseif (is_string($customizedFile) && str_contains($customizedFile, 'tuckbox')) {
        //         $tuckboxPath = $customizedFile;
        //     }

        //     if (!$tuckboxPath) {
        //         throw new \RuntimeException('Tuckbox image not found in order');
        //     }

        //     $absoluteBoxPath = Storage::disk('public')->path($tuckboxPath);
        //     $mimeType        = mime_content_type($absoluteBoxPath) ?: 'image/png';

        //     $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
        //         name:       'tuckbox-outside',
        //         folderId:   $folderId,
        //         filePath:   $absoluteBoxPath,
        //         fileName:   basename($absoluteBoxPath),
        //         mimeType:   $mimeType,
        //         hasProofed: true,
        //     ));

        //     $boxFileId = data_get($boxFileResponse, 'result.id')
        //         ?? throw new \RuntimeException('No file ID for tuckbox image');

        //     $tgc->updateTuckBox(new UpdateTuckBoxDTO(
        //         tuckboxId:         $tuckboxId,
        //         outsideId:         $boxFileId,
        //         hasProofedOutside: true,
        //     ));

        //     $this->setStatus($jobId, 'running', 'Tuckbox image attached.');

        //     Log::info('Tuckbox uploaded', ['box_file_id' => $boxFileId]);

        // } catch (Throwable $e) {
        //     $this->setStatus($jobId, 'failed', 'Tuckbox upload failed: ' . $e->getMessage());
        //     $this->cleanup($jobId);
        //     return;
        // }

        // ── Step 8: Create shipping address ──────────────────────────────
        try {
            $this->setStatus($jobId, 'running', 'Creating shipping address...');

            $shipping = $order->shippingInformation;
            if (!$shipping) {
                throw new \RuntimeException('Shipping information not found for order');
            }

            $addressResponse = $tgc->createAddress(new CreateAddressDTO(
                name:        $shipping->first_name . ' ' . $shipping->last_name,
                address1:    $shipping->address1,
                city:        $shipping->city,
                state:       $shipping->state ?? 'N/A',
                postalCode:  $shipping->zipcode,
                country:     $shipping->country ?? 'US',
                phoneNumber: $shipping->phone,
                company:     $shipping->company ?? null,
                address2:    $shipping->address2 ?? null,
            ));

            $addressId = data_get($addressResponse, 'result.id')
                ?? throw new \RuntimeException('No address ID returned from TGC');

            $this->setStatus($jobId, 'running', 'Shipping address created.');

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Address creation failed: ' . $e->getMessage());
            $this->cleanup($jobId);
            return;
        }

        // ── Step 9: Attach address to cart ────────────────────────────────
        try {
            $tgc->updateCart($cartId, ['shipping_address_id' => $addressId]);
            $this->setStatus($jobId, 'running', 'Shipping address attached to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart address update failed: ' . $e->getMessage());
            $this->cleanup($jobId);
            return;
        }

        // ── Step 10: Add SKU to cart ──────────────────────────────────────
        try {
            $tgc->addSkuToCart(new AddToCartDTO(
                cartId:   $cartId,
                skuId:    $skuId,
                quantity: 1,
            ));

            $this->setStatus($jobId, 'completed', 'Deck published and added to cart.', [
                'uploaded' => $total,
                'total'    => $total,
                'cart_id'  => $cartId,
            ]);

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart SKU step failed: ' . $e->getMessage());
        }

        $this->cleanup($jobId);
    }

    private function resizeImageTo825x1125(string $blob): string
    {
        $src = imagecreatefromstring($blob);
        if (!$src) {
            throw new \RuntimeException('Failed to create image from blob');
        }

        $dst = imagecreatetruecolor(825, 1125);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, 825, 1125, imagesx($src), imagesy($src));

        ob_start();
        imagepng($dst);
        $resized = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $resized;
    }

    private function setStatus(string $jobId, string $status, string $message, array $extra = []): void
    {
        Cache::put("tgc_job:{$jobId}", array_merge([
            'status'     => $status,
            'message'    => $message,
            'job_id'     => $jobId,
            'updated_at' => now()->toISOString(),
        ], $extra), now()->addHours(2));
    }

    private function cleanup(string $jobId): void
    {
        app(CardMergeService::class)->cleanup('temp/' . $jobId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('PublishDeckJob FAILED', [
            'order_id' => $this->orderId,
            'error'    => $e->getMessage(),
            'trace'    => $e->getTraceAsString(),
        ]);
    }
}