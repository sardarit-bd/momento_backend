<?php

namespace App\Services\PaymentGateway;

use App\Interface\PaymentGateway\PaymentGatewayInterface;

class CashOnDeliveryGateway implements PaymentGatewayInterface
{
    public function createCheckout(array $data)
    {
        return (object) [
            'url' => null,
            'id' => null,
        ];
    }
}
