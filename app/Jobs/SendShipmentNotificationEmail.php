<?php

namespace App\Jobs;

use App\Models\OrderShipment;
use App\Notifications\OrderShipped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendShipmentNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $shipmentId,
    ) {}

    public function handle(): void
    {
        $shipment = OrderShipment::find($this->shipmentId);

        if (! $shipment) {
            return;
        }

        if ($shipment->notified_at) {
            Log::info('Shipment notification already sent', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'notified_at' => $shipment->notified_at,
            ]);

            return;
        }

        $order = $shipment->order;
        $user = $order->user ?? null;

        if (! $user || empty($user->email)) {
            Log::warning('Shipment notification skipped: no user email', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
            ]);

            return;
        }

        try {
            $user->notify(new OrderShipped($order, $shipment));

            $shipment->update(['notified_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Shipment notification email failed', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'recipient' => $user->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
