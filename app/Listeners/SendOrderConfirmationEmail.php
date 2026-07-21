<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Jobs\SendOrderConfirmationEmail as SendOrderConfirmationEmailJob;
use Illuminate\Support\Facades\Cache;

class SendOrderConfirmationEmail
{
    /**
     * Handle the event.
     */
    public function handle(OrderPlaced $event): void
    {
        if (empty($event->order->email)) {
            return;
        }

        $lockKey = 'order_confirmation_sent_'.$event->order->getKey();

        if (! Cache::add($lockKey, true, 3600)) {
            return;
        }

        SendOrderConfirmationEmailJob::dispatch($event->order);
    }
}
