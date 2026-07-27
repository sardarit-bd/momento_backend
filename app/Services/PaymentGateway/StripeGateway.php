<?php

namespace App\Services\PaymentGateway;

use App\Interface\PaymentGateway\PaymentGatewayInterface;
use App\Models\Order;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayInterface
{
    protected $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(StripeConfigService::getSecretKey());
    }

    /**
     * This method creates a Stripe checkout session
     */
    public function createCheckout(array $data)
    {
        $lineItems = [];

        foreach ($data['items'] as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $data['currency'] ?? 'usd',
                    'unit_amount' => intval($item['price']),
                    'product_data' => ['name' => $item['name']],
                ],
                'quantity' => intval($item['qty']),
            ];
        }

        $sessionConfig = [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $data['success_url'],
            'cancel_url' => $data['cancel_url'],
            'metadata' => [
                'order_id' => $data['metadata']['order_id'],
                'first_name' => $data['metadata']['first_name'] ?? '',
                'last_name' => $data['metadata']['last_name'] ?? '',
                'phone' => $data['metadata']['phone'] ?? '',
                'address' => $data['metadata']['address'] ?? '',
                'city' => $data['metadata']['city'] ?? '',
                'zipcode' => $data['metadata']['zipcode'] ?? '',
            ],
            'expires_at' => $data['expires_at'] ?? now()->addHour(1)->timestamp,
            'after_expiration' => $data['after_expiration'] ?? [
                'recovery' => ['enabled' => true],
            ],
            'allow_promotion_codes' => true,
        ];

        if (! empty($data['customer_email'])) {
            $customer = $this->stripe->customers->create([
                'email' => $data['customer_email'],
            ]);
            $sessionConfig['customer'] = $customer->id;
        }

        $session = $this->stripe->checkout->sessions->create($sessionConfig);

        return $session;
    }

    /**
     * Helper to build the JSON the frontend expects.
     */
    public function buildOrderResponse(Order $order)
    {
        $images = [];

        foreach ($order->orderItems as $item) {
            $imagePath = $item->product->image ?? null;

            if (! $imagePath) {
                continue;
            }

            $fullPath = public_path($imagePath);

            if (! file_exists($fullPath)) {
                $fullPath = storage_path('app/public/'.ltrim($imagePath, '/'));
            }

            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath) ?: 'image/png';
                $b64 = base64_encode(file_get_contents($fullPath));
                $images[] = "data:{$mime};base64,{$b64}";
            }
        }

        return [
            'AllProductImage' => $images,
            'City' => $order->city ?? '',
            'address' => $order->address,
            'email' => $order->email,
            'name' => $order->name,
            'payment_method' => 'stripe',
            'phone' => $order->phone,
            'roundTotolPrice' => $order->total,
            'zipcode' => $order->zipcode ?? '',
        ];
    }
}
