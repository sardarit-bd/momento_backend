<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderShipment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly OrderShipment $shipment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing('orderItems.product');

        return (new MailMessage)
            ->subject("Your order #{$this->order->id} has shipped!")
            ->view('emails.shipment-notification', [
                'customerName' => $this->order->name,
                'order' => $this->order,
                'orderItems' => $this->order->orderItems,
                'shipment' => $this->shipment,
            ]);
    }
}
