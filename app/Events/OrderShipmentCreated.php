<?php

namespace App\Events;

use App\Models\OrderShipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderShipmentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public OrderShipment $shipment) {}
}
