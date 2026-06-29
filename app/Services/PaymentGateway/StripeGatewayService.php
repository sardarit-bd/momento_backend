<?php

namespace App\Services\PaymentGateway;

use App\Models\Product;
use App\Models\ShippingInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\TGC\TradingBoxCompositeService;

class StripeGatewayService
{
    private const DECK_RANKS = ['ace', 'king', 'queen', 'jack', 'joker'];

    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email',
            'phone'      => 'required|string|max:50',
            'address1'   => 'required|string|max:500',
            'address2'   => 'nullable|string|max:500',
            'city'       => 'required|string|max:100',
            'state'      => 'nullable|string|max:100',
            'country'    => 'nullable|string|max:100',
            'zipcode'    => 'required|string|max:20',
            'gateway'    => 'required|string|in:stripe,cod,cash_on_delivery',
            'items'      => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty'        => 'required|integer|min:1',
            'items.*.price'      => 'nullable|numeric|min:0',
            'items.*.name'       => 'required|string',
            'items.*.FinalPDF'     => 'nullable|array',
            'items.*.FinalProduct' => 'nullable|array',
            'tuckbox_characters'   => 'nullable|array',
            'tuckbox_characters.*' => 'nullable|string',
            'trading_box_pack_title'   => 'nullable|string|max:50',
            'tradingBoxPackTitle'      => 'nullable|string|max:50',
            'trading_box_created_for'  => 'nullable|string|max:50',
            'tradingBoxCreatedFor'     => 'nullable|string|max:50',
        ]);

        try {
            $validatedItems = [];
            $trustedTotal = 0;

            // Validate all products first
            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                if (!$product) {
                    return response()->json([
                        'success' => false,
                        'message' => "Product with ID {$item['product_id']} not found",
                    ], 404);
                }

                if (strtolower(trim((string) $product->status)) !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => "Product '{$product->name}' is currently unavailable",
                    ], 400);
                }

                $sellingPrice = $product->offer_price > 0
                    ? $product->offer_price
                    : $product->price;

                $quantity = (int) $item['qty'];
                $lineTotal = $sellingPrice * $quantity;
                $trustedTotal += $lineTotal;

                $validatedItems[] = [
                    'product_id'         => $product->id,
                    'name'               => $product->name,
                    'product_type'       => $product->type ?? 'simple',
                    'customization_mode' => $item['customization_mode'] ?? null,
                    'qty'                => $quantity,
                    'price'              => $sellingPrice,
                    'total'              => $lineTotal,
                    'FinalPDF'           => $item['FinalPDF'] ?? null,
                    'FinalProduct'       => $item['FinalProduct'] ?? [],
                ];
            }

            // Handle COD
            if ($request->gateway === 'cod' || $request->gateway === 'cash_on_delivery') {
                return $this->createCODOrder($request, $validatedItems, $trustedTotal);
            }

            // Handle Stripe - Create order BEFORE redirect
            return $this->createStripeOrder($request, $validatedItems, $trustedTotal);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);

        // StripeGatewayService.php — replace the outer catch block
        } catch (\Exception $e) {
            Log::error('Checkout session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class' => get_class($e),               
                'file'  => $e->getFile(),               
                'line'  => $e->getLine(),               
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session',
                'error'   => 'Internal server error',  
            ], 500);
        }
    }

    protected function createStripeOrder($request, $validatedItems, $trustedTotal)
    {
        $validatedItems = array_map(function($item) {
            unset($item['character_image']);
            return $item;
        }, $validatedItems);
        DB::beginTransaction();

        try {

            $user = auth('api')->user();
            $order = \App\Models\Order::create([
                'user_id'  => $user?->id ?? ($request->userID ?? null),
                'name'     => $request->first_name . ' ' . $request->last_name,
                'email'    => $user?->email ?? $request->email,
                'phone'    => $request->phone,
                'address1' => $request->address1,
                'address2' => $request->address2,
                'city'     => $request->city,
                'state'    => $request->state,
                'country'  => $request->country,
                'zipcode'  => $request->zipcode,
                'total'    => $trustedTotal,
                'status'   => 'pending',
                'is_paid'  => false,
                'trading_box_pack_title'  => $this->tradingBoxValue($request, 'trading_box_pack_title',  'tradingBoxPackTitle'),
                'trading_box_created_for' => $this->tradingBoxValue($request, 'trading_box_created_for', 'tradingBoxCreatedFor'),
            ]);

            ShippingInformation::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'first_name' => $request->first_name,
                    'last_name'  => $request->last_name,
                    'phone'      => $request->phone,
                    'address1'   => $request->address1,
                    'address2'   => $request->address2,
                    'city'       => $request->city,
                    'state'      => $request->state,
                    'country'    => $request->country,
                    'zipcode'    => $request->zipcode,
                ]
            );

            $isCustomized = false;
            $customizedFiles = [];

            foreach ($validatedItems as $item) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['qty'],
                    'price'      => $item['price'],
                ]);

                // Determine if this item is customized based on product type
                $productType = strtolower($item['product_type'] ?? 'simple');
                $hasCustomization = match($productType) {
                    'trading', 'customizable' => true,
                    'simple'                  => false,
                    default                   => !empty($item['FinalProduct']),
                };

                $cardSaveResult = $this->storeOrderItemCards(
                    $orderItem,
                    $item['FinalProduct'] ?? [],
                    $item['customization_mode'] ?? null
                );

                $productType = strtolower($item['product_type'] ?? '');
                if (in_array($productType, ['deck', 'deck-card', 'poker-deck']) || ($item['customization_mode'] ?? null) === 'deck') {
                    if (!empty($request->tuckbox_image)) {
                        $tuckboxRaw = $request->tuckbox_image;
                        if (str_contains($tuckboxRaw, 'base64,')) {
                            $tuckboxRaw = explode('base64,', $tuckboxRaw)[1];
                        }
                        $tuckboxBlob = base64_decode($tuckboxRaw);
                        $orderItem->update([
                            'tuckbox_image_blob' => $tuckboxBlob,
                            'tuckbox_image_mime' => 'image/png',
                        ]);
                    }
                }

                if ($cardSaveResult['count'] > 0) {
                    $isCustomized = true;
                    $orderItem->update([
                        'customization_mode'   => $cardSaveResult['mode'],
                        'card_design_count'    => $cardSaveResult['count'],
                        'customization_images' => null,
                    ]);
                } elseif ($hasCustomization) {
                    $isCustomized = true;
                }

                // Handle PDF files
                if (!empty($item['FinalPDF']['data'])) {
                    $pdfData  = base64_decode($item['FinalPDF']['data']);
                    $fileName = 'custom_pdf_' . time() . '_' . $item['product_id'] . '.pdf';
                    $filePath = 'customized_files/' . $fileName;

                    Storage::disk('public')->put($filePath, $pdfData);
                    $customizedFiles[] = $filePath;
                    $isCustomized = true;
                }
            }


            if (!empty($request->tuckbox_characters)) {
                $characterBlobs = [];

                foreach ($request->tuckbox_characters as $b64) {
                    if (empty($b64)) continue;
                    $raw = $b64;
                    if (str_contains($raw, 'base64,')) {
                        $raw = explode('base64,', $raw, 2)[1];
                    }
                    $decoded = base64_decode($raw);
                    if ($decoded !== false) {
                        $characterBlobs[] = $decoded;
                    }
                }

                if (!empty($characterBlobs)) {
                    $compositeService = new \App\Services\TGC\TuckBoxCompositeService();
                    $tuckboxBlob = $compositeService->composite($characterBlobs);

                    $order->loadMissing('orderItems.product');

                    $deckItem = $order->orderItems->first(function ($item) {
                        $type = strtolower((string) ($item->product?->type ?? ''));
                        return in_array($type, ['deck', 'deck-card', 'poker-deck'])
                            || $item->customization_mode === 'deck';
                    });

                    if ($deckItem) {
                        $deckItem->update([
                            'tuckbox_image_blob' => $tuckboxBlob,
                            'tuckbox_image_mime' => 'image/png',
                        ]);
                    }

                    // Save to disk for admin preview
                    $fileName = 'tuckbox_' . time() . '.png';
                    $filePath = 'customized_files/' . $fileName;
                    Storage::disk('public')->put($filePath, $tuckboxBlob);
                    $customizedFiles[] = $filePath;
                    $isCustomized = true;
                }
            }

            // Trading box composite — isolated, does not touch deck card logic
            $packTitle  = $this->tradingBoxValue($request, 'trading_box_pack_title',  'tradingBoxPackTitle');
            $createdFor = $this->tradingBoxValue($request, 'trading_box_created_for', 'tradingBoxCreatedFor');

            if (!empty($packTitle) || !empty($createdFor)) {
                $tradingBoxService = new TradingBoxCompositeService();
                $tradingBoxPath = $tradingBoxService->composite(
                    $packTitle  ?? '',
                    $createdFor ?? ''
                );

                if ($tradingBoxPath) {
                    $customizedFiles[] = $tradingBoxPath;
                    $isCustomized = true;
                }
            }

            // Update order with customization summary
            $order->update([
                'is_customized'   => $isCustomized,
                'customized_file' => !empty($customizedFiles) ? $customizedFiles : null,
            ]);


            $order->orderHasPaids()->create([
                'amount' => $trustedTotal,
                'method' => 'stripe',
                'status' => 'pending',
                'notes'  => 'Awaiting Stripe payment',
            ]);

            $stripeItems = array_map(function ($item) {
                return [
                    'name'  => $item['name'],
                    'qty'   => $item['qty'],
                    'price' => round($item['price'] * 100),
                ];
            }, $validatedItems);


            $gateway = PaymentGatewayFactory::make('stripe');

            $session = $gateway->createCheckout([
                'items'       => $stripeItems,
                'success_url' => rtrim(config('app.frontend_url'), '/') . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => rtrim(config('app.frontend_url'), '/') . '/payment/cancel?session_id={CHECKOUT_SESSION_ID}',

                'currency'    => 'usd',
                'metadata' => [
                    'order_id'   => $order->id,
                    'first_name' => $request->first_name,
                    'last_name'  => $request->last_name,
                    'phone'      => $request->phone,
                    'address1'   => $request->address1,
                    'address2'   => $request->address2,
                    'city'       => $request->city,
                    'state'      => $request->state,
                    'country'    => $request->country,
                    'zipcode'    => $request->zipcode,
                ],
                'expires_at' => now()->addHour(1)->timestamp,
                'after_expiration' => [
                    'recovery' => ['enabled' => true],
                ],
            ]);


            $order->update([
                'stripe_session_id' => $session->id,
            ]);

            DB::commit();

            return response()->json([
                'success'           => true,
                'checkout_url'      => $session->url,
                'order_id'          => $order->id,
                'stripe_session_id' => $session->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Stripe order creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function createCODOrder($request, $validatedItems, $trustedTotal)
    {
        DB::beginTransaction();

        try {
            $user = auth('api')->user();

            $order = \App\Models\Order::create([
                'user_id'  => $user?->id ?? ($request->userID ?? null),
                'name'     => $request->first_name . ' ' . $request->last_name,
                'email'    => $user?->email ?? $request->email,
                'phone'    => $request->phone,
                'address1' => $request->address1,
                'address2' => $request->address2,
                'city'     => $request->city,
                'state'    => $request->state,
                'country'  => $request->country,
                'zipcode'  => $request->zipcode,
                'total'    => $trustedTotal,
                'status'   => 'pending',
                'is_paid'  => false,
                'trading_box_pack_title'  => $this->tradingBoxValue($request, 'trading_box_pack_title',  'tradingBoxPackTitle'),
                'trading_box_created_for' => $this->tradingBoxValue($request, 'trading_box_created_for', 'tradingBoxCreatedFor'),
            ]);

            ShippingInformation::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'first_name' => $request->first_name,
                    'last_name'  => $request->last_name,
                    'phone'      => $request->phone,
                    'address1'   => $request->address1,
                    'address2'   => $request->address2,
                    'city'       => $request->city,
                    'state'      => $request->state,
                    'country'    => $request->country,
                    'zipcode'    => $request->zipcode,
                ]
            );

            foreach ($validatedItems as $item) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['qty'],
                    'price'      => $item['price'],
                ]);

                $cardSaveResult = $this->storeOrderItemCards($orderItem, $item['FinalProduct'] ?? [], $item['customization_mode'] ?? null);
                if ($cardSaveResult['count'] > 0) {
                    $orderItem->update([
                        'customization_mode' => $cardSaveResult['mode'],
                        'card_design_count' => $cardSaveResult['count'],
                        'customization_images' => null,
                    ]);
                }

                if (!empty($item['FinalPDF']['data'])) {
                    $pdfData = base64_decode($item['FinalPDF']['data']);
                    $fileName = 'custom_pdf_' . time() . '_' . $item['product_id'] . '.pdf';
                    $filePath = 'customized_files/' . $fileName;

                    Storage::disk('public')->put($filePath, $pdfData);

                    $order->update([
                        'is_customized'   => true,
                        'customized_file' => $filePath,
                    ]);
                }
            }

            $order->orderHasPaids()->create([
                'amount' => $trustedTotal,
                'method' => 'cod',
                'status' => 'pending',
                'notes'  => 'Cash on Delivery',
            ]);

            DB::commit();

            return response()->json([
                'success'  => true,
                'gateway'  => 'cod',
                'message'  => 'Order placed successfully using Cash on Delivery.',
                'order_id' => $order->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('COD order creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Persist customized card images for one order item.
     * Accepts multiple payload shapes for backward compatibility.
     */
    private function storeOrderItemCards($orderItem, array $finalProduct, ?string $requestedMode = null): array
    {

        if (empty($finalProduct)) {
            return ['count' => 0, 'mode' => 'none'];
        }

        $entries = [];
        
        foreach ($finalProduct as $entry) {
            if (is_string($entry)) {
                $entries[] = ['image' => $entry];
                continue;
            }

            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        if (empty($entries)) {
            return ['count' => 0, 'mode' => 'none'];
        }

        $mode = $this->detectCustomizationMode($entries, $requestedMode);
        $isTrading = $mode === 'trading';
        $tradingGroupKey = $isTrading ? (string) Str::uuid() : null;

        foreach ($entries as $index => $entry) {
            $base64 = $entry['image'] ?? $entry['data'] ?? null;
            if (!is_string($base64) || $base64 === '') {
                continue;
            }

            [$mime, $blob] = $this->decodeBase64Image($base64);
            if ($blob === null) {
                continue;
            }

            $side = $entry['side'] ?? null;
            $rank = $entry['rank'] ?? null;
            if ($mode === 'deck') {
                $side = 'single';
                $rank = $rank ?: (self::DECK_RANKS[$index] ?? null);
            } else {
                $side = in_array($side, ['front', 'back'], true) ? $side : ($index === count($entries) - 1 ? 'back' : 'front');
                $rank = null;
            }

            $characterImage = $entry['character_image'] ?? '';
            [$characterMime, $characterBlob] = $this->decodeBase64Image($characterImage);

            $orderItem->cards()->create([
                'card_pair_key'    => $isTrading ? ($entry['card_pair_key'] ?? $tradingGroupKey) : null,
                'card_type'        => $mode,
                'side'             => $side,
                'rank'             => $rank,
                'slot_name'        => $entry['name'] ?? null,
                'position'         => $index + 1,
                'image_blob'       => $blob,
                'image_mime'       => $mime,
                'character_blob'   => $characterBlob ?: null,
                'character_mime'   => $characterBlob ? $characterMime : null,
                'image_size_bytes' => strlen($blob),
                'image_sha256'     => hash('sha256', $blob),
            ]);
        }

        return [
            'count' => $orderItem->cards()->count(),
            'mode' => $mode,
        ];
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function decodeBase64Image(string $payload): array
    {
        if (trim($payload) === '') {
            return [null, null];
        }

        $mime = null;
        $encoded = $payload;

        if (preg_match('/^data:([a-zA-Z0-9\\/\\-\\+\\.]+);base64,(.*)$/s', $payload, $matches)) {
            $mime = $matches[1];
            $encoded = $matches[2];
        }

        $decoded = base64_decode(str_replace(' ', '+', $encoded), true);
        if ($decoded === false || $decoded === '') {
            return [null, null];
        }

        return [$mime ?? 'image/png', $decoded];
    }

    private function detectCustomizationMode(array $entries, ?string $requestedMode = null): string
    {
        if (in_array($requestedMode, ['trading', 'deck'], true)) {
            return $requestedMode;
        }

        foreach ($entries as $entry) {
            $side = $entry['side'] ?? null;
            if (in_array($side, ['front', 'back'], true)) {
                return 'trading';
            }
        }

        foreach ($entries as $entry) {
            $rank = strtolower((string) ($entry['rank'] ?? ''));
            if (in_array($rank, self::DECK_RANKS, true)) {
                return 'deck';
            }
        }

        return in_array(count($entries), [4, 5], true) ? 'deck' : 'trading';
    }

    /**
     * Read a value from the request, accepting both snake_case and
     * camelCase keys, with empty strings normalised to null.
     */
    private function tradingBoxValue(Request $request, string $snake, string $camel): ?string
    {
        $value = $request->input($snake, $request->input($camel));
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}