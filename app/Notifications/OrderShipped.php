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
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing('orderItems.product');

        $mail = (new MailMessage)
            ->subject("Your order #{$this->order->id} has shipped!")
            ->greeting("Hi {$this->order->name},")
            ->line('Great news — your order is on its way.')
            ->line("Order #: {$this->order->id}");

        foreach ($this->order->orderItems as $item) {
            $name = optional($item->product)->name ?? 'Item';
            $mail->line("- {$name} x {$item->quantity}");
        }

        if ($this->shipment->tracking_number) {
            $mail->line("Tracking number: {$this->shipment->tracking_number}");
        }

        if ($this->shipment->tracking_url) {
            $mail->action('Track your package', $this->shipment->tracking_url);
        }

        return $mail->line('Thanks for your order!');
    }
}