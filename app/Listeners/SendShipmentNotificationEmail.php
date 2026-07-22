<?php

namespace App\Listeners;

use App\Events\OrderShipmentCreated;
use App\Jobs\SendShipmentNotificationEmail as SendShipmentNotificationEmailJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendShipmentNotificationEmail
{
    public function handle(OrderShipmentCreated $event): void
    {
        $user = $event->shipment->order->user ?? null;

        if (! $user || empty($user->email)) {
            Log::warning('Shipment notification skipped: no user email', [
                'shipment_id' => $event->shipment->id,
                'order_id' => $event->shipment->order_id,
            ]);

            return;
        }

        $lockKey = 'shipment_notification_sent_'.$event->shipment->getKey();

        if (! Cache::add($lockKey, true, 3600)) {
            return;
        }

        SendShipmentNotificationEmailJob::dispatch($event->shipment->getKey());
    }
}
