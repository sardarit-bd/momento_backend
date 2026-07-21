<?php

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\TGCWebhookLog;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function tgcWebhookPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 'event_'.fake()->uuid(),
        'type' => 'data',
        'event' => 'ReceiptShipped',
        'payload' => [
            'id' => 'receipt_123',
            'shipments' => [
                [
                    'id' => 'ship_1',
                    'tracking_number' => '1Z999AA10123456784',
                    'tracking_url_provider' => 'https://example.com/track/1',
                    'carrier' => 'UPS',
                ],
            ],
        ],
    ], $overrides);
}

function signedTgcRequest(array $message, string $privateKey): array
{
    $epoch = (string) time();
    $messageJson = json_encode($message);
    $hmac = hash_hmac('sha256', $epoch.'.'.$messageJson, $privateKey);

    return [
        'epoch' => $epoch,
        'message' => $messageJson,
        'hmac' => $hmac,
    ];
}

beforeEach(function () {
    Config::set('services.tgc.private_key', 'test-private-key');
});

it('accepts a valid signature and creates a log plus shipment rows', function () {
    $order = Order::factory()->create(['tgc_receipt_id' => 'receipt_123']);

    $payload = tgcWebhookPayload();
    $request = signedTgcRequest($payload, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)
        ->assertStatus(200)
        ->assertJson(['received' => true]);

    expect(TGCWebhookLog::count())->toBe(1);
    $log = TGCWebhookLog::first();
    expect($log->hmac_verified)->toBeTrue()
        ->and($log->status)->toBe('processed')
        ->and($log->matched_order_id)->toBe($order->id);

    expect(OrderShipment::count())->toBe(1);
    $shipment = OrderShipment::first();
    expect($shipment->order_id)->toBe($order->id)
        ->and($shipment->tgc_shipment_id)->toBe('ship_1')
        ->and($shipment->carrier)->toBe('UPS')
        ->and($shipment->notified_at)->toBeNull();
});

it('rejects an invalid signature and does not touch order data', function () {
    $order = Order::factory()->create(['tgc_receipt_id' => 'receipt_123']);

    $payload = tgcWebhookPayload();
    $epoch = (string) time();
    $messageJson = json_encode($payload);

    $this->postJson('/api/webhooks/tgc/receipt-shipped', [
        'epoch' => $epoch,
        'message' => $messageJson,
        'hmac' => 'deadbeef',
    ])->assertStatus(401);

    $log = TGCWebhookLog::first();
    expect($log->hmac_verified)->toBeFalse()
        ->and($log->status)->toBe('failed');

    expect(OrderShipment::count())->toBe(0);
});

it('processes a duplicate delivery only once', function () {
    $order = Order::factory()->create(['tgc_receipt_id' => 'receipt_123']);

    $eventId = 'event_dup_'.fake()->uuid();
    $payload = tgcWebhookPayload(['id' => $eventId]);
    $request = signedTgcRequest($payload, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)->assertStatus(200);
    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)->assertStatus(200);

    expect(OrderShipment::count())->toBe(1);
    expect(TGCWebhookLog::count())->toBe(2);
    expect(TGCWebhookLog::where('status', 'processed')->count())->toBe(2);
});

it('logs an unknown receipt id as unmatched without throwing and still returns 200', function () {
    $payload = tgcWebhookPayload(['payload' => ['id' => 'receipt_unknown', 'shipments' => [['id' => 'ship_x']]]]);
    $request = signedTgcRequest($payload, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)->assertStatus(200);

    $log = TGCWebhookLog::first();
    expect($log->status)->toBe('unmatched')
        ->and($log->tgc_receipt_id)->toBe('receipt_unknown');

    expect(OrderShipment::count())->toBe(0);
});

it('creates one shipment row per shipment in a multi-shipment payload', function () {
    $order = Order::factory()->create(['tgc_receipt_id' => 'receipt_123']);

    $payload = tgcWebhookPayload([
        'payload' => [
            'id' => 'receipt_123',
            'shipments' => [
                ['id' => 'ship_a', 'tracking_number' => 'T1', 'tracking_url_provider' => 'https://x/a', 'carrier' => 'UPS'],
                ['id' => 'ship_b', 'tracking_number' => 'T2', 'tracking_url_provider' => 'https://x/b', 'carrier' => 'FedEx'],
                ['id' => 'ship_c', 'tracking_number' => 'T3', 'tracking_url_provider' => 'https://x/c', 'carrier' => 'USPS'],
            ],
        ],
    ]);
    $request = signedTgcRequest($payload, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)->assertStatus(200);

    expect(OrderShipment::count())->toBe(3);
    expect(OrderShipment::where('tgc_shipment_id', 'ship_b')->first()->carrier)->toBe('FedEx');
});

it('returns 200 immediately without writing shipments when queue is async', function () {
    Config::set('queue.default', 'database');

    $order = Order::factory()->create(['tgc_receipt_id' => 'receipt_123']);
    $payload = tgcWebhookPayload();
    $request = signedTgcRequest($payload, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $request)->assertStatus(200);

    $log = TGCWebhookLog::first();
    expect($log->status)->toBe('received');
    expect(OrderShipment::count())->toBe(0);

    Config::set('queue.default', 'sync');
});

it('derives a distinct dedupe key per payload and a stable key on replay', function () {
    $orderA = Order::factory()->create(['tgc_receipt_id' => 'receipt_A']);
    $orderB = Order::factory()->create(['tgc_receipt_id' => 'receipt_B']);

    $payloadA = tgcWebhookPayload([
        'payload' => [
            'id' => 'receipt_A',
            'shipments' => [['id' => 'ship_A1', 'tracking_number' => 'T-A1', 'tracking_url_provider' => 'https://x/a', 'carrier' => 'UPS']],
        ],
    ]);
    $payloadB = tgcWebhookPayload([
        'payload' => [
            'id' => 'receipt_B',
            'shipments' => [['id' => 'ship_B1', 'tracking_number' => 'T-B1', 'tracking_url_provider' => 'https://x/b', 'carrier' => 'FedEx']],
        ],
    ]);

    $reqA = signedTgcRequest($payloadA, 'test-private-key');
    $reqB = signedTgcRequest($payloadB, 'test-private-key');

    $this->postJson('/api/webhooks/tgc/receipt-shipped', $reqA)->assertStatus(200);
    $this->postJson('/api/webhooks/tgc/receipt-shipped', $reqB)->assertStatus(200);

    $logA = TGCWebhookLog::where('tgc_receipt_id', 'receipt_A')->first();
    $logB = TGCWebhookLog::where('tgc_receipt_id', 'receipt_B')->first();

    expect($logA->dedupe_key)->not->toBe($logB->dedupe_key);

    $dupA = signedTgcRequest($payloadA, 'test-private-key');
    $this->postJson('/api/webhooks/tgc/receipt-shipped', $dupA)->assertStatus(200);

    $logAReplay = TGCWebhookLog::where('tgc_receipt_id', 'receipt_A')->orderByDesc('id')->first();
    expect($logAReplay->dedupe_key)->toBe($logA->dedupe_key);

    expect(TGCWebhookLog::where('dedupe_key', $logA->dedupe_key)->count())->toBe(2);
});
