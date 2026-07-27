<?php

namespace App\Interface\PaymentGateway;

interface PaymentGatewayInterface
{
    public function createCheckout(array $data);
}
