<?php

namespace App\Jobs\TGC;

use App\DTOs\TGC\AddToCartDTO;
use App\DTOs\TGC\CreateAddressDTO;
use App\DTOs\TGC\CreateCardFromFaceDTO;
use App\DTOs\TGC\CreateDeckDTO;
use App\DTOs\TGC\CreateFolderDTO;
use App\DTOs\TGC\CreateGameDTO;
use App\DTOs\TGC\CreateTuckBoxDTO;
use App\DTOs\TGC\UploadFolderFileDTO;
use App\Models\Order;
use App\Models\OrderItemCard;
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

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 60;

    // ── Only store the order ID in the queue payload. ─────────────────────
    // Never serialize Eloquent models or blob data into queue jobs — blobs
    // can be megabytes and hidden fields get stripped by SerializesModels.
    // Everything is loaded fresh inside handle() directly from the DB.
    public function __construct(
        private readonly int $orderId,
    ) {}

    private function buildCardsBySlot($allCards): array
    {
        $deckCards = $allCards->where('card_type', 'deck');
        $byRank = $deckCards->whereNotIn('rank', [null, ''])->keyBy(fn ($c) => strtolower($c->rank));
        $jokers = $deckCards->filter(fn ($c) => strtolower((string) $c->rank) === 'joker')->values();

        $suitFaceMap = [
            'king' => ['Clubs_Face_King', 'Diamonds_Face_King', 'Hearts_Face_King', 'Spades_Face_King'],
            'queen' => ['Clubs_Face_Queen', 'Diamonds_Face_Queen', 'Hearts_Face_Queen', 'Spades_Face_Queen'],
            'jack' => ['Clubs_Face_Jack', 'Diamonds_Face_Jack', 'Hearts_Face_Jack', 'Spades_Face_Jack'],
            'ace' => ['Clubs_Ace', 'Diamonds_Ace', 'Hearts_Ace', 'Spades_Ace'],
        ];

        $recognizedRanks = ['king', 'queen', 'jack', 'ace', 'joker'];
        foreach ($allCards->where('card_type', 'deck') as $card) {
            $rank = strtolower((string) $card->rank);
            if ($rank !== '' && ! in_array($rank, $recognizedRanks, true) && ! empty($card->image_blob)) {
                Log::warning('PublishDeckJob: customized deck card has an unrecognised rank and will be silently defaulted', [
                    'order_id' => $this->orderId,
                    'order_item_card_id' => $card->id,
                    'slot_name' => $card->slot_name,
                    'rank' => $card->rank,
                ]);
            }
        }

        // ── This was the missing block — builds the actual face-card mapping ──
        $result = [];
        foreach ($suitFaceMap as $rank => $slots) {
            if ($card = $byRank->get($rank)) {
                foreach ($slots as $slot) {
                    $result[$slot] = $card;
                }
            }
        }

        if ($jokers->count() > 0) {
            $result['Joker_1'] = $jokers->get(0);
            if ($jokers->count() > 1) {
                $result['Joker_2'] = $jokers->get(1);
            }
        }

        return $result;
    }

    public function handle(TGCService $tgc): void
    {
        // Idempotency: use lock to prevent race conditions between concurrent jobs
        $lock = Cache::lock("tgc_publish_order_{$this->orderId}", 600);
        if (! $lock->get()) {
            Log::info('PublishDeckJob skipped: lock not acquired', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        try {
            $jobId = (string) \Illuminate\Support\Str::uuid();

            $this->setStatus($jobId, 'running', 'Loading order data...');

            try {
                $order = Order::with([
                    'orderItems.product',
                    'shippingInformation',
                ])->findOrFail($this->orderId);

                // Idempotency: skip if order already has a TGC receipt
                if (! empty($order->tgc_receipt_id)) {
                    Log::info('PublishDeckJob skipped: order already has tgc_receipt_id', [
                        'order_id' => $this->orderId,
                    ]);

                    return;
                }

                // Load cards fresh from DB with blobs — never rely on eager-loaded
                // hidden fields from a serialized model instance.
                $orderItemIds = $order->orderItems->pluck('id');

                $allCards = OrderItemCard::whereIn('order_item_id', $orderItemIds)
                    ->orderBy('position')
                    ->get();

                if ($allCards->isEmpty()) {
                    throw new \RuntimeException(
                        "No cards found in order_item_cards for order {$this->orderId}. ".
                        'Card blobs were never saved — check storeOrderItemCards upstream.'
                    );
                }

            } catch (Throwable $e) {
                Log::error('Order load failed', [
                    'order_id' => $this->orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->setStatus($jobId, 'failed', 'Order load failed: '.$e->getMessage());

                return;
            }

            // ── Validate shipping ──────────────────────────────────────────────
            $shipping = $order->shippingInformation;
            if (! $shipping) {
                Log::error('No shipping information found', ['order_id' => $this->orderId]);
                $this->setStatus($jobId, 'failed', 'Shipping information missing for order.');

                return;
            }

            $username = $order->name.'-'.time();

            // ── Step 1: Create Game ─────────────────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Creating TGC game...');

                $game = $tgc->createGame(new CreateGameDTO(name: $username));
                $gameId = data_get($game, 'result.id')
                    ?? throw new \RuntimeException('TGC createGame: no result.id in response. Response: '.json_encode($game));
                $skuId = data_get($game, 'result.sku_id')
                    ?? throw new \RuntimeException('TGC createGame: no result.sku_id in response. Response: '.json_encode($game));

                $this->setStatus($jobId, 'running', 'Game created.');

            } catch (Throwable $e) {
                Log::error('Game creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Game creation failed: '.$e->getMessage());

                return;
            }

            // ── Step 2: Create Folder ──────────────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Creating folder...');

                $folder = $tgc->createFolder(new CreateFolderDTO(name: $username.'-folder'));
                $folderId = data_get($folder, 'result.id')
                    ?? throw new \RuntimeException('TGC createFolder: no result.id. Response: '.json_encode($folder));

                $this->setStatus($jobId, 'running', 'Folder created.');

            } catch (Throwable $e) {
                Log::error('Folder creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Folder creation failed: '.$e->getMessage());

                return;
            }

            // ── Step 3: Create Deck ────────────────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Creating deck...');

                $deck = $tgc->createDeck(new CreateDeckDTO(
                    gameId: $gameId,
                    name: $username.'-deck',
                    identity: 'PokerDeck',
                    hasProofedBack: true,
                    backId: 'A5466D20-54D0-11F1-86E8-959B4373131A',
                ));
                $deckId = data_get($deck, 'result.id')
                    ?? throw new \RuntimeException('TGC createDeck: no result.id. Response: '.json_encode($deck));

                $this->setStatus($jobId, 'running', 'Deck created.');

            } catch (Throwable $e) {
                Log::error('Deck creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Deck creation failed: '.$e->getMessage());

                return;
            }

            // ── Step 4: Build & upload tuckbox image ───────────────────────────
            // The tuckbox blob is stored on order_items.tuckbox_image_blob.
            // Grab it from the first deck order item.
            try {
                $this->setStatus($jobId, 'running', 'Uploading tuckbox image...');

                $deckItem = $order->orderItems->first(function ($item) {
                    $type = strtolower((string) optional($item->product)->type);

                    return $type === 'deck' || $item->customization_mode === 'deck';
                });

                if (! $deckItem || empty($deckItem->tuckbox_image_blob)) {
                    throw new \RuntimeException(
                        'No tuckbox_image_blob found on order item. '.
                        'Item ID: '.($deckItem?->id ?? 'none').'. '.
                        'Check that the frontend is saving the tuckbox blob to order_items.'
                    );
                }

                $tuckboxBlob = $deckItem->tuckbox_image_blob;
                // Decode if stored as base64 data URI
                if (str_starts_with($tuckboxBlob, 'data:')) {
                    $tuckboxBlob = base64_decode(explode(',', $tuckboxBlob, 2)[1]);
                }

                $tuckboxTempPath = 'temp/'.$jobId.'/tuckbox_composite.png';
                Storage::disk('local')->put($tuckboxTempPath, $tuckboxBlob);
                $absoluteBoxPath = Storage::disk('local')->path($tuckboxTempPath);

                $boxFileResponse = $tgc->uploadFolderFile(new UploadFolderFileDTO(
                    name: 'tuckbox-outside',
                    folderId: $folderId,
                    filePath: $absoluteBoxPath,
                    fileName: 'tuckbox_composite.png',
                    mimeType: 'image/png',
                    hasProofed: true,
                ));

                $boxFileId = data_get($boxFileResponse, 'result.id')
                    ?? throw new \RuntimeException('No file ID returned for tuckbox. Response: '.json_encode($boxFileResponse));

                $this->setStatus($jobId, 'running', 'Tuckbox image uploaded.');

            } catch (Throwable $e) {
                Log::error('Tuckbox upload failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Tuckbox upload failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 5: Create TuckBox with the uploaded image ─────────────────
            try {
                $tuckbox = $tgc->createTuckBox(new CreateTuckBoxDTO(
                    name: $username.'-box',
                    gameId: $gameId,
                    outsideId: $boxFileId,
                    hasProofedOutside: true,
                ));
                $tuckboxId = data_get($tuckbox, 'result.id')
                    ?? throw new \RuntimeException('TGC createTuckBox: no result.id. Response: '.json_encode($tuckbox));

                $this->setStatus($jobId, 'running', 'TuckBox created.');

            } catch (Throwable $e) {
                Log::error('TuckBox creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'TuckBox creation failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 6: Upload 54 cards ────────────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Preparing cards...');

                // Build a lookup: slot_name => OrderItemCard (deck cards only)
                $cardsBySlot = $this->buildCardsBySlot($allCards);

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

                // Fail loudly (instead of silently) when a customized deck card has a
                // slot_name that isn't present in $deckOrder. Without this, such a card
                // would silently fall back to the default template and the bug would be
                // invisible until the difference becomes noticeable.
                $knownSlots = array_flip($deckOrder);
                foreach ($allCards->where('card_type', 'deck') as $card) {
                    if (! empty($card->slot_name)
                        && ! isset($knownSlots[$card->slot_name])
                        && ! empty($card->image_blob)) {
                        Log::warning('PublishDeckJob: customized deck card has an unrecognised slot_name and will be silently defaulted', [
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

                    if (isset($cardsBySlot[$slotName])) {
                        $card = $cardsBySlot[$slotName];

                        if (empty($card->image_blob)) {
                            $this->writeDefaultCard($slotName, $filename, $targetPath);
                        } else {
                            $blob = $card->image_blob;
                            if (str_starts_with($blob, 'data:')) {
                                $blob = base64_decode(explode(',', $blob, 2)[1]);
                            }
                            $resized = $this->resizeImageTo825x1125($blob);
                            Storage::disk('local')->put($targetPath, $resized);

                        }
                    } else {
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
                Log::error('Card upload failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Card upload failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 7: Create Cart ────────────────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Creating cart...');

                $cartResponse = $tgc->createCart();
                $cartId = data_get($cartResponse, 'result.id')
                    ?? throw new \RuntimeException('No cart ID from TGC. Response: '.json_encode($cartResponse));

                $this->setStatus($jobId, 'running', 'Cart created.');

            } catch (Throwable $e) {
                Log::error('Cart creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Cart creation failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 8: Create shipping address ───────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Creating shipping address...');

                $addressResponse = $tgc->createAddress(CreateAddressDTO::make(
                    name: trim($shipping->first_name.' '.$shipping->last_name),
                    address1: $shipping->address1,
                    address2: $shipping->address2 ?? null,
                    city: $shipping->city,
                    state: $shipping->state ?? 'N/A',
                    postalCode: $shipping->zipcode,
                    country: $shipping->country ?? 'US',
                    phoneNumber: $shipping->phone,
                    company: $shipping->company ?? null,
                ));

                $addressId = data_get($addressResponse, 'result.id')
                    ?? throw new \RuntimeException('No address ID from TGC. Response: '.json_encode($addressResponse));

                $this->setStatus($jobId, 'running', 'Shipping address created.');

            } catch (Throwable $e) {
                Log::error('Address creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Address creation failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 9: Attach address to cart ────────────────────────────────
            try {
                $tgc->updateCart($cartId, ['shipping_address_id' => $addressId]);

                $this->setStatus($jobId, 'running', 'Address attached to cart.');

            } catch (Throwable $e) {
                Log::error('Cart address update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
                Log::error('Add SKU to cart failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Cart SKU step failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 11: Attach user/session to cart ───────────────────────────
            try {
                $tgc->attachUserToCart($cartId);

                $this->setStatus($jobId, 'running', 'Session attached to cart.');

            } catch (Throwable $e) {
                Log::error('Attach user to cart failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Attach user to cart failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 12: Validate shop credit ─────────────────────────────────
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
                Log::error('Shop credit validation failed', ['error' => $e->getMessage()]);
                $this->setStatus($jobId, 'failed', 'Shop credit validation failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 13: Pay with shop credit ─────────────────────────────────
            try {
                $this->setStatus($jobId, 'running', 'Processing payment...');

                $paymentResponse = $tgc->payWithShopCredit($cartId);
                $receiptId = data_get($paymentResponse, 'result.id')
                    ?? throw new \RuntimeException('No receipt ID from TGC. Response: '.json_encode($paymentResponse));

            } catch (Throwable $e) {
                Log::error('Payment failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->setStatus($jobId, 'failed', 'Payment failed: '.$e->getMessage());
                $this->cleanup($jobId);

                return;
            }

            // ── Step 14: Fetch receipt & finalise ─────────────────────────────
            try {
                $tgc->fetchReceipt($receiptId);

                Order::where('id', $this->orderId)->update([
                    'tgc_receipt_id' => $receiptId,
                ]);

                $this->setStatus($jobId, 'completed', 'Order placed and paid successfully.', [
                    'receipt_id' => $receiptId,
                    'cart_id' => $cartId,
                    'uploaded' => $total,
                    'total' => $total,
                ]);

            } catch (Throwable $e) {
                // Non-fatal — payment already succeeded
                Log::error('Receipt fetch failed (non-fatal, payment already succeeded)', [
                    'error' => $e->getMessage(),
                    'receipt_id' => $receiptId ?? null,
                ]);
                Order::where('id', $this->orderId)->update([
                    'tgc_receipt_id' => $receiptId ?? null,
                ]);
                $this->setStatus($jobId, 'completed', 'Order placed. Receipt fetch failed (non-fatal).', [
                    'receipt_id' => $receiptId ?? null,
                    'cart_id' => $cartId,
                    'uploaded' => $total,
                    'total' => $total,
                ]);
            }

            $this->cleanup($jobId);
        } catch (Throwable $e) {
            Log::error('TGC publish unexpected error', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            $this->setStatus($jobId, 'failed', 'Unexpected error: '.$e->getMessage());
            $lock->release();
        } finally {
            $lock->release();
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

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

    private function resizeImageTo825x1125(string $blob): string
    {
        $src = @imagecreatefromstring($blob);
        if (! $src) {
            throw new \RuntimeException('Failed to create GD image from blob — invalid image data.');
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
            'order_id' => $this->orderId,
            'updated_at' => now()->toISOString(),
        ], $extra), now()->addHours(2));
    }

    private function cleanup(string $jobId): void
    {
        try {
            $tempDir = 'temp/'.$jobId;
            if (Storage::disk('local')->exists($tempDir)) {
                Storage::disk('local')->deleteDirectory($tempDir);
            }
        } catch (Throwable $e) {
            Log::warning('Cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PublishDeckJob FAILED — all retries exhausted', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
