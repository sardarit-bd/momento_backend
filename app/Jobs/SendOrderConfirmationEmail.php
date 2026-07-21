<?php

namespace App\Jobs;

use App\Mail\OrderReceivedMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Log;
use Throwable;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Order $order)
    {
    }

    public function handle(): void
    {
        if (empty($this->order->email)) {
            return;
        }

        try {
            Mail::to(new Address($this->order->email, $this->order->name ?: 'Customer'))
                ->send(new OrderReceivedMail($this->order));
        } catch (Throwable $e) {
            Log::error('Order confirmation email failed', [
                'order_id' => $this->order->id,
                'recipient' => $this->order->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
