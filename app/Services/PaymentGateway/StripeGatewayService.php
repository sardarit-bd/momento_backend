<?php

namespace App\Services\PaymentGateway;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StripeGatewayService
{
    private const DECK_RANKS = ['ace', 'king', 'queen', 'jack', 'joker'];

    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'phone'   => 'required|string|max:50',
            'address' => 'required|string|max:500',
            'city'    => 'nullable|string|max:100',
            'zipcode' => 'nullable|string|max:20',
            'gateway' => 'required|string|in:stripe,cod,cash_on_delivery',
            'items'   => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty'        => 'required|integer|min:1',
            'items.*.price'      => 'nullable|numeric|min:0',
            'items.*.name'       => 'required|string',
            'items.*.FinalPDF'     => 'nullable|array',
            'items.*.FinalProduct' => 'nullable|array',
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
                
                if ($product->status != 1) {
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
                    'product_id'   => $product->id,
                    'name'         => $product->name,
                    'qty'          => $quantity,
                    'price'        => $sellingPrice,
                    'total'        => $lineTotal,
                    'FinalPDF'     => $item['FinalPDF'] ?? null,
                    'FinalProduct' => $item['FinalProduct'] ?? [],
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
            
        } catch (\Exception $e) {
            Log::error('Checkout session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    protected function createStripeOrder($request, $validatedItems, $trustedTotal)
    {
        DB::beginTransaction();

        try {

            $order = \App\Models\Order::create([
                'user_id'  => auth('api')->id(),
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'address'  => $request->address,
                'city'     => $request->city,
                'zipcode'  => $request->zipcode,
                'total'    => $trustedTotal,
                'status'   => 'pending',
                'is_paid'  => false,
            ]);

            Log::info('Order created for Stripe checkout', ['order_id' => $order->id]);


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

                // Handle PDF files
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
                'method' => 'stripe',
                'status' => 'pending',
                'notes'  => 'Awaiting Stripe payment',
            ]);

            Log::info('Order items and payment record created', ['order_id' => $order->id]);


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
                'success_url' => env('FRONTEND_URL') . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => env('FRONTEND_URL') . '/payment/cancel?session_id={CHECKOUT_SESSION_ID}',
                'currency'    => 'usd',
                'metadata'    => [
                    'order_id' => $order->id,
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

            Log::info('Stripe checkout session created', [
                'stripe_session_id' => $session->id,
                'order_id' => $order->id,
            ]);

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
            $order = \App\Models\Order::create([
                'user_id'  => auth('api')->id(),
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'address'  => $request->address,
                'city'     => $request->city,
                'zipcode'  => $request->zipcode,
                'total'    => $trustedTotal,
                'status'   => 'pending',
                'is_paid'  => false,
            ]);

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

            Log::info('COD order created', ['order_id' => $order->id]);

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

            $orderItem->cards()->create([
                'card_pair_key' => $isTrading ? ($entry['card_pair_key'] ?? $tradingGroupKey) : null,
                'card_type' => $mode,
                'side' => $side,
                'rank' => $rank,
                'position' => $index + 1,
                'image_blob' => $blob,
                'image_mime' => $mime,
                'image_size_bytes' => strlen($blob),
                'image_sha256' => hash('sha256', $blob),
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
        $mime = null;
        $encoded = $payload;

        if (preg_match('/^data:([a-zA-Z0-9\\/\\-\\+\\.]+);base64,(.*)$/s', $payload, $matches)) {
            $mime = $matches[1];
            $encoded = $matches[2];
        }

        $decoded = base64_decode(str_replace(' ', '+', $encoded), true);
        if ($decoded === false) {
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
}
