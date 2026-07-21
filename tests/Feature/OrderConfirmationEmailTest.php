<?php

use App\Events\OrderPlaced;
use App\Jobs\SendOrderConfirmationEmail;
use App\Mail\OrderReceivedMail;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

it('sends an order confirmation email for a valid recipient', function () {
    Mail::fake();

    $order = new Order([
        'id' => 101,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'total' => '49.99',
        'status' => 'pending',
    ]);

    $job = new SendOrderConfirmationEmail($order);
    $job->handle();

    Mail::assertQueued(OrderReceivedMail::class, function ($mail) use ($order) {
        return $mail->hasTo($order->email);
    });
});

it('fires the order placed event when an order is created', function () {
    Event::fake();

    $order = new Order([
        'id' => 202,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'total' => '49.99',
        'status' => 'pending',
    ]);

    event(new OrderPlaced($order));

    Event::assertDispatched(OrderPlaced::class, function (OrderPlaced $event) use ($order) {
        return $event->order->id === $order->id;
    });
});
