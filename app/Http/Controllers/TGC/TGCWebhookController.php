<?php

// app/Http/Controllers/TGC/TGCWebhookController.php

namespace App\Http\Controllers\TGC;

use App\Http\Controllers\Controller;
use App\Jobs\TGC\ProcessTGCReceiptShippedJob;
use App\Models\TGCWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TGCWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $epoch = (string) $request->input('epoch');
        $message = (string) $request->input('message');
        $receivedHmac = (string) $request->input('hmac');

        $privateKey = config('services.tgc.private_key');

        $expectedHmac = hash_hmac('sha256', $epoch.'.'.$message, $privateKey);
        $signatureValid = $receivedHmac !== '' && hash_equals($expectedHmac, $receivedHmac);

        $decoded = json_decode($message, true) ?? [];
        $type = data_get($decoded, 'type');
        $event = data_get($decoded, 'event');

        $deliveryId = data_get($decoded, 'id');
        $dedupeKey = $this->dedupeKey($request, $decoded);

        $log = TGCWebhookLog::create([
            'tgc_webhook_event_id' => $deliveryId,
            'type' => $type,
            'event' => $event,
            'tgc_receipt_id' => data_get($decoded, 'payload.id'),
            'dedupe_key' => $dedupeKey,
            'hmac_verified' => $signatureValid,
            'status' => 'received',
            'payload' => $decoded,
            'received_at' => now(),
        ]);

        if (! $signatureValid) {
            Log::warning('TGC webhook signature invalid', ['log_id' => $log->id]);

            $log->update([
                'status' => 'failed',
                'error' => 'Invalid signature',
                'processed_at' => now(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        ProcessTGCReceiptShippedJob::dispatch($log->id);

        return response()->json(['received' => true], 200);
    }

    private function dedupeKey(Request $request, array $decoded): ?string
    {
        $receiptId = data_get($decoded, 'payload.id');

        if (! $receiptId) {
            $raw = $request->input('message', '');

            return $raw !== '' ? 'raw:'.md5($raw) : null;
        }

        $shipments = data_get($decoded, 'payload.shipments', []);
        $shipmentKeys = collect($shipments)
            ->map(fn ($s) => data_get($s, 'id').'|'.data_get($s, 'tracking_number'))
            ->sort()
            ->implode(',');

        return 'receipt:'.$receiptId.':'.md5($shipmentKeys);
    }
}
