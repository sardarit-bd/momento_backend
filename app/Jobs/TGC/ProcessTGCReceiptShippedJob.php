<?php

namespace App\Jobs\TGC;

use App\Events\OrderShipmentCreated;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\TGCWebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTGCReceiptShippedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        private readonly int $logId,
    ) {}

    public function handle(): void
    {
        $log = TGCWebhookLog::find($this->logId);

        if (! $log) {
            return;
        }

        if ($log->status === 'processed') {
            return;
        }

        if ($log->dedupe_key) {
            $alreadyProcessed = TGCWebhookLog::where('dedupe_key', $log->dedupe_key)
                ->where('id', '!=', $log->id)
                ->where('status', 'processed')
                ->exists();

            if ($alreadyProcessed) {
                $log->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                return;
            }
        }

        $payload = data_get($log->payload, 'payload', []);
        $receiptId = data_get($payload, 'id');

        $order = Order::where('tgc_receipt_id', $receiptId)->first();

        if (! $order) {
            $log->update([
                'status' => 'unmatched',
                'tgc_receipt_id' => $receiptId,
                'processed_at' => now(),
            ]);

            Log::warning('TGC ReceiptShipped: no matching order', [
                'log_id' => $log->id,
                'tgc_receipt_id' => $receiptId,
            ]);

            return;
        }

        $shipments = data_get($payload, 'shipments', []);

        foreach ($shipments as $shipmentData) {
            $tgcShipmentId = data_get($shipmentData, 'id');

            if (! $tgcShipmentId) {
                continue;
            }

            $shipment = OrderShipment::firstOrCreate(
                ['tgc_shipment_id' => $tgcShipmentId],
                [
                    'order_id' => $order->id,
                    'tracking_number' => data_get($shipmentData, 'tracking_number'),
                    'tracking_url' => data_get($shipmentData, 'tracking_url_provider'),
                    'carrier' => data_get($shipmentData, 'carrier'),
                    'shipped_at' => now(),
                    'raw_payload' => $shipmentData,
                ]
            );

            if ($shipment->wasRecentlyCreated && ! $shipment->notified_at) {
                event(new OrderShipmentCreated($shipment));
            }
        }

        $log->update([
            'status' => 'processed',
            'matched_order_id' => $order->id,
            'tgc_receipt_id' => $receiptId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Seam for the future customer-email feature.
     *
     * Explicitly a no-op stub: do NOT send mail, notifications, or any
     * external messaging from here. Replace the body later without touching
     * the surrounding correlation/shipment logic.
     */
    private function notifyCustomerOfShipment(OrderShipment $shipment): void
    {
        Log::info('notification pending — not yet implemented', [
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'tgc_shipment_id' => $shipment->tgc_shipment_id,
        ]);
    }
}
