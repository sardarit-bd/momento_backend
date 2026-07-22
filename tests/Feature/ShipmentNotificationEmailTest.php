<?php

use App\Events\OrderShipmentCreated;
use App\Jobs\SendShipmentNotificationEmail;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('fires the order shipment created event and listener dispatches the job', function () {
    Bus::fake();

    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $shipment = OrderShipment::create([
        'order_id' => $order->id,
        'tgc_shipment_id' => 'ship_1',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => 'https://example.com/track/1',
        'carrier' => 'UPS',
        'shipped_at' => now(),
    ]);

    event(new OrderShipmentCreated($shipment));

    Bus::assertDispatched(SendShipmentNotificationEmail::class, function ($job) use ($shipment) {
        return $job->shipmentId === $shipment->id;
    });
});

it('sends a shipment notification email when notified_at is null', function () {
    Notification::fake();

    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $shipment = OrderShipment::create([
        'order_id' => $order->id,
        'tgc_shipment_id' => 'ship_1',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => 'https://example.com/track/1',
        'carrier' => 'UPS',
        'shipped_at' => now(),
    ]);

    $job = new SendShipmentNotificationEmail($shipment->id);
    $job->handle();

    Notification::assertSentTo($user, OrderShipped::class);

    expect($shipment->fresh()->notified_at)->not->toBeNull();
});

it('does not send when notified_at is already set', function () {
    Notification::fake();

    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $shipment = OrderShipment::create([
        'order_id' => $order->id,
        'tgc_shipment_id' => 'ship_1',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => 'https://example.com/track/1',
        'carrier' => 'UPS',
        'shipped_at' => now(),
        'notified_at' => now(),
    ]);

    $job = new SendShipmentNotificationEmail($shipment->id);
    $job->handle();

    Notification::assertNothingSentTo($user);

    expect($shipment->fresh()->notified_at)->toEqual($shipment->notified_at);
});

it('listener does not dispatch the job when the order has no user', function () {
    Bus::fake();

    $order = Order::factory()->create(['user_id' => null]);
    $shipment = OrderShipment::create([
        'order_id' => $order->id,
        'tgc_shipment_id' => 'ship_1',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => 'https://example.com/track/1',
        'carrier' => 'UPS',
        'shipped_at' => now(),
    ]);

    event(new OrderShipmentCreated($shipment));

    Bus::assertNotDispatched(SendShipmentNotificationEmail::class);

    expect($shipment->fresh()->notified_at)->toBeNull();
});
