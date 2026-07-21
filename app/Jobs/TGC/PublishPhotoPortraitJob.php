<?php

namespace App\Jobs\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateAddressDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\CreateGameDTO;
use App\DTOs\TGC\CreateTuckBoxDTO;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\Models\Order;
use App\Models\OrderItemCard;
use App\Services\TGC\CardMergeService;
use App\Services\TGC\PhotoPortraitBoxCompositeService;
use App\Services\TGC\TGCService;
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

/**
 * Publishes a Photo Portrait order to The Game Crafter:
 *  - uploads each distinct photo card as a deck face (1 copy each, since every
 *    photo card is a unique portrait),
 *  - regenerates the photo portrait box from the user's source photos + drag/zoom
 *    positions (or falls back to the pre-composited tuckbox blob),
 *  - creates the TGC tuckbox, cart, address and pays with shop credit.
 */
class PublishPhotoPortraitJob implements ShouldQueue
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
            Log::info('PublishPhotoPortraitJob skipped: lock not acquired', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $jobId = (string) Str::uuid();

        $this->setStatus($jobId, 'running', 'Loading order data...');

        try {
            $order = Order::with([
                'orderItems.product',
                'orderItems.cards',
                'shippingInformation',
            ])->findOrFail($this->orderId);

            // Idempotency: skip if order already has a TGC receipt
            if (! empty($order->tgc_receipt_id)) {
                Log::info('PublishPhotoPortraitJob skipped: order already has tgc_receipt_id', [
                    'order_id' => $this->orderId,
                ]);
                $lock->release();

                return;
            }
        } catch (Throwable $e) {
            Log::error('PublishPhotoPortraitJob failed to load order', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            $lock->release();

            return;
        }

        $username = $order->name.'-'.time();

        $this->setStatus($jobId, 'running', 'Starting TGC photo portrait publish...');

        // ── Locate the photo portrait order item ────────────────────────────
        $photoItem = $order->orderItems->first(function ($item) {
            $type = strtolower((string) optional($item->product)->type);

            return $type === 'photo' || $item->customization_mode === 'photo';
        });

        if (! $photoItem) {
            Log::warning('PublishPhotoPortraitJob: no photo item found', ['order_id' => $this->orderId]);
            $this->setStatus($jobId, 'completed', 'No photo portrait item in this order; nothing to publish.');
            $lock->release();

            return;
        }

        try {
            $game = $tgc->createGame(new CreateGameDTO(name: $username));
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

        // ── Step 2: Create Folder ──────────────────────────────────────────
        try {
            $folder = $tgc->createFolder(new \App\DTOs\TGC\CreateFolderDTO(name: $username.'-folder'));
            $folderId = data_get($folder, 'result.id')
                ?? throw new \RuntimeException('TGC folder creation failed: no id');
            $this->setStatus($jobId, 'running', 'Folder created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Folder creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 3: Create Deck ────────────────────────────────────────────
        try {
            $deck = $tgc->createDeck(new \App\DTOs\TGC\CreateDeckDTO(
                gameId: $gameId,
                name: $username.'-photo',
                identity: 'PokerDeck',
                hasProofedBack: true,
                backId: 'A5466D20-54D0-11F1-86E8-959B4373131A',
            ));
            $deckId = data_get($deck, 'result.id')
                ?? throw new \RuntimeException('TGC deck creation failed: no id');
            $this->setStatus($jobId, 'running', 'Deck created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Deck creation failed: '.$e->getMessage());

            return;
        }

        // ── Step 4: Build & upload photo portrait box ──────────────────────
        try {
            $this->setStatus($jobId, 'running', 'Compositing photo portrait box...');

            $storedBoxImages = $photoItem->photo_box_images ?? null;
            $fallbackBlob = $photoItem->tuckbox_image_blob ?? null;

            $compositeBlob = null;
            if (! empty($storedBoxImages) && is_array($storedBoxImages)) {
                $compositor = app(PhotoPortraitBoxCompositeService::class);
                $compositeBlob = $compositor->composite($storedBoxImages, 2325, 1950, $fallbackBlob);
            } elseif (! empty($fallbackBlob)) {
                $compositeBlob = $fallbackBlob;
            }

            if (empty($compositeBlob)) {
                throw new \RuntimeException('No photo portrait box image available (no positions and no tuckbox blob).');
            }

            if (str_starts_with($compositeBlob, 'data:')) {
                $compositeBlob = base64_decode(explode(',', $compositeBlob, 2)[1]);
            }

            $boxTempPath = 'temp/'.$jobId.'/photo_box.png';
            Storage::disk('local')->put($boxTempPath, $compositeBlob);
            $absoluteBoxPath = Storage::disk('local')->path($boxTempPath);

            $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                name: 'tuckbox-outside',
                folderId: $folderId,
                filePath: $absoluteBoxPath,
                fileName: 'photo_box_composite.png',
                mimeType: 'image/png',
                hasProofed: true,
            ));

            $boxFileId = data_get($boxFileResponse, 'result.id')
                ?? throw new \RuntimeException('No file ID for photo box');

            $this->setStatus($jobId, 'running', 'Photo box uploaded.');

        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Photo box failed: '.$e->getMessage());
            Log::error('Photo box failed', ['error' => $e->getMessage()]);
            $this->cleanup($jobId);

            return;
        }

        // ── Step 5: Create TuckBox ─────────────────────────────────────────
        try {
            $tuckbox = $tgc->createTuckBox(new CreateTuckBoxDTO(
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

        // ── Step 6: Build the full 54-card deck, replacing customized slots ──
        // Mirrors PublishDeckJob: the photo portrait deck is a standard 54-card
        // Poker deck. The user's photo cards (each carrying a TGC slot_name such
        // as Clubs_Face_King) replace the matching default slots; every other
        // slot is filled with the default template card so the full deck of 54
        // is always sent to The Game Crafter.
        try {
            $this->setStatus($jobId, 'running', 'Preparing photo cards...');

            // Build a lookup: slot_name => OrderItemCard (photo cards only)
            $cardsBySlot = $photoItem->cards()
                ->whereNotNull('image_blob')
                ->whereIn('card_type', ['deck', 'photo'])
                ->get()
                ->keyBy('slot_name');

            $deckOrder = [
                'Clubs_Ace',         'Clubs_Number_2',    'Clubs_Number_3',    'Clubs_Number_4',
                'Clubs_Number_5',    'Clubs_Number_6',    'Clubs_Number_7',    'Clubs_Number_8',
                'Clubs_Number_9',    'Clubs_Number_10',   'Clubs_Face_Jack',   'Clubs_Face_Queen',
                'Clubs_Face_King',   'Diamonds_Ace',      'Diamonds_Number_2', 'Diamonds_Number_3',
                'Diamonds_Number_4', 'Diamonds_Number_5', 'Diamonds_Number_6', 'Diamonds_Number_7',
                'Diamonds_Number_8', 'Diamonds_Number_9', 'Diamonds_Number_10', 'Diamonds_Face_Jack',
                'Diamonds_Face_Queen', 'Diamonds_Face_King', 'Hearts_Ace',       'Hearts_Number_2',
                'Hearts_Number_3',   'Hearts_Number_4',   'Hearts_Number_5',   'Hearts_Number_6',
                'Hearts_Number_7',   'Hearts_Number_8',   'Hearts_Number_9',   'Hearts_Number_10',
                'Hearts_Face_Jack',  'Hearts_Face_Queen', 'Hearts_Face_King',  'Spades_Ace',
                'Spades_Number_2',   'Spades_Number_3',   'Spades_Number_4',   'Spades_Number_5',
                'Spades_Number_6',   'Spades_Number_7',   'Spades_Number_8',   'Spades_Number_9',
                'Spades_Number_10',  'Spades_Face_Jack',  'Spades_Face_Queen', 'Spades_Face_King',
                'Joker_1',           'Joker_2',
            ];

            $total = count($deckOrder); // 54
            $tempDir = 'temp/'.$jobId;
            $cardPaths = [];

            // Warn (don't silently drop) when a customized photo card has a
            // slot_name that isn't part of the standard 54-card deck.
            $knownSlots = array_flip($deckOrder);
            foreach ($cardsBySlot as $card) {
                if (! empty($card->slot_name)
                    && ! isset($knownSlots[$card->slot_name])) {
                    Log::warning('PublishPhotoPortraitJob: photo card has an unrecognised slot_name and will be skipped', [
                        'order_id' => $this->orderId,
                        'order_item_card_id' => $card->id,
                        'slot_name' => $card->slot_name,
                        'rank' => $card->rank,
                    ]);
                }
            }

            foreach ($deckOrder as $slotName) {
                $filename = $slotName.'.png';
                $targetPath = $tempDir.'/'.$filename;

                if (isset($cardsBySlot[$slotName]) && ! empty($cardsBySlot[$slotName]->image_blob)) {
                    $blob = $cardsBySlot[$slotName]->image_blob;
                    if (str_starts_with($blob, 'data:')) {
                        $blob = base64_decode(explode(',', $blob, 2)[1]);
                    }
                    $resized = $this->resizeImageTo825x1125($blob);
                    Storage::disk('local')->put($targetPath, $resized);
                } else {
                    // No customized card for this slot → use the default template.
                    $this->writeDefaultCard($slotName, $filename, $targetPath);
                }

                $cardPaths[] = Storage::disk('local')->path($targetPath);
            }

            // ── Upload each card to TGC ────────────────────────────────────
            foreach ($cardPaths as $index => $absolutePath) {
                $cardNumber = $index + 1;
                $cardName = sprintf('Card %02d', $cardNumber);
                $mimeType = mime_content_type($absolutePath) ?: 'image/png';

                $fileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                    name: $cardName,
                    folderId: $folderId,
                    filePath: $absolutePath,
                    fileName: basename($absolutePath),
                    mimeType: $mimeType,
                    hasProofed: true,
                ));

                $faceFileId = data_get($fileResponse, 'result.id')
                    ?? throw new \RuntimeException(
                        "No file ID for card {$cardNumber} ({$deckOrder[$index]}). Response: ".json_encode($fileResponse)
                    );

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
            Log::error('Photo card upload failed', ['error' => $e->getMessage()]);
            $this->setStatus($jobId, 'failed', 'Card upload failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 7: Create Cart ────────────────────────────────────────────
        try {
            $this->setStatus($jobId, 'running', 'Creating cart...');
            $cartResponse = $tgc->createCart();
            $cartId = data_get($cartResponse, 'result.id')
                ?? throw new \RuntimeException('No cart ID from TGC');
            $this->setStatus($jobId, 'running', 'Cart created.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart creation failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 8: Create shipping address ────────────────────────────────
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

        // ── Step 9: Attach address to cart ─────────────────────────────────
        try {
            $tgc->updateCart($cartId, ['shipping_address_id' => $addressId]);
            $this->setStatus($jobId, 'running', 'Address attached to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Cart address update failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 10: Add SKU to cart ───────────────────────────────────────
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

        // ── Step 11: Attach user to cart ───────────────────────────────────
        try {
            $tgc->attachUserToCart($cartId);
            $this->setStatus($jobId, 'running', 'Session attached to cart.');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Attach user failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 12: Validate shop credit ──────────────────────────────────
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

        // ── Step 13: Pay with shop credit ──────────────────────────────────
        try {
            $paymentResponse = $tgc->payWithShopCredit($cartId);
            $receiptId = data_get($paymentResponse, 'result.id')
                ?? throw new \RuntimeException('No receipt ID from payment');
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'failed', 'Payment failed: '.$e->getMessage());
            $this->cleanup($jobId);

            return;
        }

        // ── Step 14: Fetch receipt ─────────────────────────────────────────
        try {
            $tgc->fetchReceipt($receiptId);
            Order::where('id', $this->orderId)->update([
                'tgc_receipt_id' => $receiptId,
            ]);

            $this->setStatus($jobId, 'completed', 'Photo portrait order placed successfully.', [
                'receipt_id' => $receiptId,
                'cart_id' => $cartId,
                'uploaded' => $total ?? 0,
                'total' => $total ?? 0,
            ]);
        } catch (Throwable $e) {
            $this->setStatus($jobId, 'completed', 'Order placed. Receipt fetch failed.', [
                'receipt_id' => $receiptId ?? null,
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

    /**
     * Copy the default template card for a given TGC slot into the temp dir.
     * Identical to PublishDeckJob::writeDefaultCard so the photo portrait deck
     * fills non-customized slots with the same standard 54-card artwork.
     */
    private function writeDefaultCard(string $slotName, string $filename, string $targetPath): void
    {
        $defaultPath = 'cards/'.$filename;

        if (! Storage::disk('local')->exists($defaultPath)) {
            throw new \RuntimeException(
                "Default card missing: storage/app/cards/{$filename}. ".
                "Slot: {$slotName}. Ensure all 54 default card PNGs exist."
            );
        }

        Storage::disk('local')->copy($defaultPath, $targetPath);
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
        Log::error('PublishPhotoPortraitJob FAILED', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
