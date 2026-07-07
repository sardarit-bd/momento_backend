<?php
// app/Http/Controllers/TGC/TGCWebhookController.php

namespace App\Http\Controllers\TGC;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\TGCWebhookLog;
use App\Notifications\OrderShipped;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class TGCWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $epoch = (string) $request->input('epoch');
        $message = (string) $request->input('message');
        $receivedHmac = (string) $request->input('hmac');

        $expectedHmac = hash_hmac('sha256', $epoch . $message, config('services.tgc.api_key'));
        $signatureValid = $receivedHmac !== '' && hash_equals($expectedHmac, $receivedHmac);

        $decoded = json_decode($message, true) ?? [];
        $type = data_get($decoded, 'type');
        $event = data_get($decoded, 'event');

        $log = TGCWebhookLog::create([
            'tgc_webhook_event_id' => data_get($decoded, 'id'),
            'type' => $type,
            'event' => $event,
            'signature_valid' => $signatureValid,
            'payload' => $decoded,
        ]);

        if (! $signatureValid) {
            Log::warning('TGC webhook signature invalid', ['log_id' => $log->id]);
            $log->update(['error' => 'Invalid signature', 'processed_at' => now()]);
            return response()->json(['received' => true], 200);
        }

        if ($type !== 'data' || $event !== 'ReceiptShipped') {
            $log->update(['processed_at' => now()]);
            return response()->json(['received' => true], 200);
        }

        try {
            $this->processReceiptShipped($decoded, $log);
        } catch (\Throwable $e) {
            Log::error('TGC webhook processing failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
            $log->update(['error' => $e->getMessage(), 'processed_at' => now()]);
        }

        return response()->json(['received' => true], 200);
    }

    private function processReceiptShipped(array $decoded, TGCWebhookLog $log): void
    {
        $receiptPayload = data_get($decoded, 'payload', []);
        $receiptId = data_get($receiptPayload, 'id');

        $order = Order::where('tgc_receipt_id', $receiptId)->first();

        if (! $order) {
            $log->update([
                'error' => "No matching order for tgc_receipt_id={$receiptId}",
                'processed_at' => now(),
            ]);
            return;
        }

        $shipments = data_get($receiptPayload, 'shipments', []);
        if (empty($shipments)) {
            $log->update(['processed_at' => now()]);
            return;
        }

        foreach ($shipments as $shipmentData) {
            $tgcShipmentId = data_get($shipmentData, 'id');
            if (! $tgcShipmentId) {
                continue;
            }

            DB::transaction(function () use ($order, $tgcShipmentId, $shipmentData) {
                $shipment = OrderShipment::firstOrCreate(
                    ['tgc_shipment_id' => $tgcShipmentId],
                    [
                        'order_id' => $order->id,
                        'tracking_number' => data_get($shipmentData, 'tracking_number'),
                        'tracking_url' => data_get($shipmentData, 'tracking_url_provider'),
                        'shipped_at' => now(),
                        'raw_payload' => $shipmentData,
                    ]
                );

                if (! $shipment->notified_at) {
                    Notification::route('mail', $order->email)
                        ->notify(new OrderShipped($order, $shipment));

                    $shipment->update(['notified_at' => now()]);
                }
            });
        }

        $log->update(['processed_at' => now()]);
    }
}