<?php

namespace App\Jobs\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateAddressDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\Services\TGC\CardMergeService;
use App\Services\TGC\TGCService;
use App\Services\TGC\TradingBoxCompositeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Log;
use Throwable;

class PublishTradingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 60;

    public function __construct(
        private readonly int $orderId,
    ) {}

    public function handle(TGCService $tgc): void
    {
        // Idempotency: use lock to prevent race conditions between concurrent jobs
        $lock = Cache::lock("tgc_publish_order_{$this->orderId}", 600);
        if (! $lock->get()) {
            Log::info('PublishTradingJob skipped: lock not acquired', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        // Idempotency: skip if order already has a TGC receipt
        $existingOrder = \App\Models\Order::find($this->orderId);
        if ($existingOrder && ! empty($existingOrder->tgc_receipt_id)) {
            Log::info('PublishTradingJob skipped: order already has tgc_receipt_id', [
                'order_id' => $this->orderId,
            ]);
            $lock->release();

            return;
        }

        try {
            $order = \App\Models\Order::with([
                'orderItems.cards',
                'shippingInformation',
            ])->findOrFail($this->orderId);
        } catch (Throwable $e) {
            Log::error('PublishTradingJob failed to load order', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            $lock->release();

            return;
        }

        $jobId = (string) Str::uuid();
        $username = $order->name.'-'.time();

        $this->setStatus($jobId, 'running', 'Starting TGC trading publish...');

        // ── Step 1: Create Game ──────────────────────────────────────────
        try {
            $game = $tgc->createGame(new \App\DTOs\TGC\CreateGameDTO(name: $username));
            $gameId = data_get($game, 'result.id')
                ?? throw new \RuntimeException('TGC game creation failed: no id');
            $skuId = data_get($game, 'result.sku_id')
                ?? throw new \RuntimeException('TGC game creation failed: no sku_id');
            $this->setStatus($jobId, 'running', 'Game created.');
        } catch (Throwable $e) {
            Log::error('Game creation failed', ['error' => $e->getMessage()]);
            $this->setStatus($jobId, 'failed', 'Game creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 2: Create Folder ────────────────────────────────────────
        try {
            $folder = $tgc->createFolder(new \App\DTOs\TGC\CreateFolderDTO(name: $username.'-folder'));
            $folderId = data_get($folder, 'result.id')
                ?? throw new \RuntimeException('TGC folder creation failed: no id');
            $this->setStatus($jobId, 'running', 'Folder created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Folder creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 3: Create Deck ──────────────────────────────────────────
        // try {
        //     $deck   = $tgc->createDeck(new \App\DTOs\TGC\CreateDeckDTO(
        //         gameId:         $gameId,
        //         name:           $username . '-trading',
        //         identity:       'PokerDeck',
        //         hasProofedBack: true,
        //         backId:         'A5466D20-54D0-11F1-86E8-959B4373131A',
        //     ));
        //     $deckId = data_get($deck, 'result.id')
        //         ?? throw new \RuntimeException('TGC deck creation failed: no id');
        //     $this->setStatus($jobId, 'running', 'Deck created.');
        // } catch (Throwable $e) {
        //     $this->setStatus($jobId, 'failed', 'Deck creation failed: ' . $e->getMessage());
        //     return;
        // }
        // ── Step 3a: Upload Back Card ────────────────────────────────────
        try {
            $backCard = null;
            foreach ($order->orderItems as $item) {
                foreach ($item->cards as $card) {
                    if ($card->card_type === 'trading' && $card->side === 'back') {
                        $backCard = $card;
                        break 2;
                    }
                }
            }

            if (! $backCard) {
                throw new \RuntimeException('No back card found for trading order');
            }

            $resizedBackBlob = $this->resizeImageTo825x1125($backCard->image_blob);
            $backTempPath = 'temp/'.$jobId.'/back.png';
            Storage::disk('local')->put($backTempPath, $resizedBackBlob);

            $backFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                name: 'card-back',
                folderId: $folderId,
                filePath: Storage::disk('local')->path($backTempPath),
                fileName: 'back.png',
                mimeType: 'image/png',
                hasProofed: true,
            ));

            $backFileId = data_get($backFileResponse, 'result.id')
                ?? throw new \RuntimeException('No file ID for back card');

            $this->setStatus($jobId, 'running', 'Back card uploaded.');

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Back card upload failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 3b: Create Deck with dynamic backId ─────────────────────
        try {
            $deck = $tgc->createDeck(new \App\DTOs\TGC\CreateDeckDTO(
                gameId: $gameId,
                name: $username.'-trading',
                identity: 'PokerDeck',
                hasProofedBack: true,
                backId: $backFileId,
            ));
            $deckId = data_get($deck, 'result.id')
                ?? throw new \RuntimeException('TGC deck creation failed: no id');
            $this->setStatus($jobId, 'running', 'Deck created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Deck creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 4: Composite & Upload Trading Box ───────────────────────
        try {
            $this->setStatus($jobId, 'running', 'Compositing trading box...');

            // Get pack_title and created_for from order meta or items
            $packTitle = $order->trading_box_pack_title ?? '';
            $createdFor = $order->trading_box_created_for ?? '';

            $compositor = app(TradingBoxCompositeService::class);
            $tuckboxFilePath = $compositor->composite($packTitle, $createdFor);

            if (! $tuckboxFilePath) {
                throw new \RuntimeException('Trading box composite returned null');
            }

            $absoluteBoxPath = Storage::disk('public')->path($tuckboxFilePath);

            $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                name: 'tuckbox-outside',
                folderId: $folderId,
                filePath: $absoluteBoxPath,
                fileName: 'tradingbox_composite.png',
                mimeType: 'image/png',
                hasProofed: true,
            ));

            $boxFileId = data_get($boxFileResponse, 'result.id')
                ?? throw new \RuntimeException('No file ID for trading box');

            $this->setStatus($jobId, 'running', 'Trading box uploaded.');

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Trading box failed: '.$e->getMessage());
            Log::error('Trading box failed', ['error' => $e->getMessage()]);
            $this->cleanup($jobId);

            return;
        }

        // ── Step 5: Create TuckBox ───────────────────────────────────────
        try {
            $tuckbox = $tgc->createTuckBox(new \App\DTOs\TGC\CreateTuckBoxDTO(
                name: $username.'-box',
                gameId: $gameId,
                outsideId: $boxFileId,
                hasProofedOutside: true,
            ));
            $tuckboxId = data_get($tuckbox, 'result.id')
                ?? throw new \RuntimeException('TGC tuckbox creation failed: no id');
            $this->setStatus($jobId, 'running', 'TuckBox created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'TuckBox creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 6: Build & Upload 18 Trading Cards ──────────────────────
        try {
            $this->setStatus($jobId, 'running', 'Preparing trading cards...');

            $tradingItem = $order->orderItems->first(fn ($i) => $i->customization_mode === 'trading');

            if (! $tradingItem) {
                throw new \RuntimeException('No trading order item found');
            }

            $fronts = $tradingItem->cards
                ->where('card_type', 'trading')
                ->where('side', 'front')
                ->values()
                ->all();

            if (empty($fronts)) {
                throw new \RuntimeException('No front cards found for trading order');
            }

            $frontCount = count($fronts);
            $copiesPerDesign = match ($frontCount) {
                1 => 18,
                3 => 6,
                6 => 3,
                default => throw new \RuntimeException("Unexpected front card count: {$frontCount}"),
            };

            $cardSequence = [];
            foreach ($fronts as $frontCard) {
                for ($i = 0; $i < $copiesPerDesign; $i++) {
                    $cardSequence[] = $frontCard;
                }
            }

            $tempDir = 'temp/'.$jobId;
            $total = count($cardSequence);

            foreach ($cardSequence as $index => $card) {
                $cardNumber = $index + 1;
                $cardName = sprintf('Card %02d', $cardNumber);
                $fileName = sprintf('card_%02d.png', $cardNumber);
                $targetPath = $tempDir.'/'.$fileName;

                $resizedBlob = $this->resizeImageTo825x1125($card->image_blob);
                Storage::disk('local')->put($targetPath, $resizedBlob);
                $absolutePath = Storage::disk('local')->path($targetPath);

                if ($index === 0) {
                    Storage::disk('public')->put(
                        'debug/card_resized_'.$this->orderId.'.png',
                        $resizedBlob
                    );
                }

                $fileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                    name: $cardName,
                    folderId: $folderId,
                    filePath: $absolutePath,
                    fileName: $fileName,
                    mimeType: 'image/png',
                    hasProofed: true,
                ));

                $faceFileId = data_get($fileResponse, 'result.id')
                    ?? throw new \RuntimeException("No file ID for card {$cardNumber}");

                $tgc->createCardFromFace(new CreateCardFromFaceDTO(
                    name: $cardName,
                    deckId: $deckId,
                    faceId: $faceFileId,
                    hasProofedFace: true,
                    hasProofedBack: true,
                ));

                $this->setStatus($jobId, 'running', "Uploaded {$cardNumber}/{$total} cards.", [
                    'uploaded' => $cardNumber,
                    'total' => $total,
                ]);

                if ($cardNumber < $total) {
                    sleep(1);
                }

            }

        } catch (Throwable $e) {
            Log::error('Trading card upload failed', ['error' => $e->getMessage()]);
            $this->setStatus($jobId, 'failed', 'Card upload failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 7: Create Cart ───────────────────────────────────────────
        try {
            $cartResponse = $tgc->createCart();
            $cartId = data_get($cartResponse, 'result.id')
                ?? throw new \RuntimeException('No cart ID returned from TGC');
            $this->setStatus($jobId, 'running', 'Cart created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart creation failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 8: Create Shipping Address ──────────────────────────────
        try {
            $shipping = $order->shippingInformation;
            if (! $shipping) {
                throw new \RuntimeException('Shipping information not found');
            }

            $addressResponse = $tgc->createAddress(CreateAddressDTO::make(
                name: $shipping->first_name.' '.$shipping->last_name,
                address1: $shipping->address1,
                city: $shipping->city,
                state: $shipping->state ?? 'N/A',
                postalCode: $shipping->zipcode,
                country: $shipping->country ?? 'US',
                phoneNumber: $shipping->phone,
                company: $shipping->company ?? null,
                address2: $shipping->address2 ?? null,
            ));

            $addressId = data_get($addressResponse, 'result.id')
                ?? throw new \RuntimeException('No address ID returned');

            $this->setStatus($jobId, 'running', 'Shipping address created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Address creation failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 9: Attach Address to Cart ───────────────────────────────
        try {
            $tgc->updateCart($cartId, ['shipping_address_id' => $addressId]);
            $this->setStatus($jobId, 'running', 'Address attached to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart address update failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 10: Add SKU to Cart ──────────────────────────────────────
        try {
            $tgc->addSkuToCart(new AddToCartDTO(
                cartId: $cartId,
                skuId: $skuId,
                quantity: 1,
            ));
            $this->setStatus($jobId, 'running', 'SKU added to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Add SKU failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 11: Attach User to Cart ─────────────────────────────────
        try {
            $tgc->attachUserToCart($cartId);
            $this->setStatus($jobId, 'running', 'Session attached to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Attach user failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 12: Validate Shop Credit ────────────────────────────────
        try {
            $cartDetails = $tgc->getCart($cartId);
            $grandTotal = (float) data_get($cartDetails, 'result.grand_total', 0);
            $shopCredit = (float) data_get($cartDetails, 'result.applicable_shop_credit', 0);

            if ($shopCredit < $grandTotal) {
                throw new \RuntimeException(
                    "Insufficient shop credit: have \${$shopCredit}, need \${$grandTotal}"
                );
            }
            $this->setStatus($jobId, 'running', 'Shop credit validated.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Shop credit validation failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 13: Pay with Shop Credit ────────────────────────────────
        try {
            $paymentResponse = $tgc->payWithShopCredit($cartId);
            $receiptId = data_get($paymentResponse, 'result.id')
                ?? throw new \RuntimeException('No receipt ID from payment');

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Payment failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 14: Fetch Receipt ────────────────────────────────────────
        try {
            $tgc->fetchReceipt($receiptId);
            \App\Models\Order::where('id', $this->orderId)->update([
                'tgc_receipt_id' => $receiptId,
            ]);

            $this->setStatus($jobId, 'completed', 'Trading order placed successfully.', [
                'receipt_id' => $receiptId,
                'cart_id' => $cartId,
                'uploaded' => $total,
                'total' => $total,
            ]);

        } catch (Throwable $e) {
            // Non-fatal
            $this->setStatus($jobId, 'completed', 'Order placed. Receipt fetch failed.', [
                'receipt_id' => $receiptId,
                'cart_id' => $cartId,
            ]);
        }

        $this->cleanup($jobId);
        $lock->release();
    }

    private function resizeImageTo825x1125(string $blob): string
    {

        $src = imagecreatefromstring($blob);
        if (! $src) {
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
            'status' => $status,
            'message' => $message,
            'job_id' => $jobId,
            'updated_at' => now()->toISOString(),
        ], $extra), now()->addHours(2));
    }

    private function cleanup(string $jobId): void
    {
        app(CardMergeService::class)->cleanup('temp/'.$jobId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('PublishTradingJob FAILED', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
