<?php
namespace App\Services\PaymentGateway;

use App\Models\Order;

class OrderPaymentService
{
    public function syncOrderPaymentStatus(Order $order): void
    {
        $order->loadMissing('orderHasPaids');
        
        $payments = $order->orderHasPaids;
        
        $totalPaid = $payments
            ->where('status', 'completed')
            ->sum('amount');
        
        $orderTotal = (float) $order->total;
        $hasCompletedPayment = $payments->contains('status', 'completed');
        
        
        $shouldBePaid = $hasCompletedPayment && $totalPaid >= $orderTotal;
        
        // Only update if is_paid changed (prevents unnecessary saves & events)
        if ($order->is_paid !== $shouldBePaid) {
            $order->update([
                'is_paid' => $shouldBePaid,
            ]);
        }
    }
}