<?php

namespace App\Http\Controllers\PaymentGateway;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class WebhookController extends Controller
{
    /**
     * Handle Stripe webhook events
     * Updates existing orders when payment is completed
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = env('STRIPE_WEBHOOK_SECRET');

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );

            Log::info('Stripe webhook received', [
                'type' => $event->type,
                'id' => $event->id,
            ]);

            // Route to appropriate handler
            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'checkout.session.expired':
                    return $this->handleCheckoutExpired($event->data->object);

                default:
                    Log::info('Unhandled event type', ['type' => $event->type]);
                    return response()->json(['received' => true]);
            }

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);

        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Webhook failed'], 500);
        }
    }

    /**
     * Update order when payment is completed
     */
    protected function handleCheckoutCompleted($session)
    {
        $orderId = $session->metadata->order_id ?? null;

        if (!$orderId) {
            Log::error('Missing order_id in Stripe metadata');
            return response()->json(['error' => 'Missing order ID'], 400);
        }

        Log::info('Processing payment completion', [
            'order_id' => $orderId,
            'stripe_session_id' => $session->id,
        ]);

        $order = Order::with('orderHasPaids')->find($orderId);

        if (!$order) {
            Log::error('Order not found', ['order_id' => $orderId]);
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Prevent duplicate webhook processing
        if ($order->is_paid) {
            Log::warning('Order already marked as paid', ['order_id' => $orderId]);
            return response()->json(['received' => true, 'message' => 'Already processed']);
        }

        DB::beginTransaction();

        try {
            // 1. Update order_has_paids table
            $payment = $order->orderHasPaids()
                ->where('method', 'stripe')
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($payment) {
                $payment->update([
                    'status'         => 'completed',
                    'transaction_id' => $session->payment_intent ?? $session->id,
                    'notes'          => 'Payment completed successfully via Stripe.',
                ]);

                Log::info('Payment record updated (observer will sync is_paid)', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $session->payment_intent ?? $session->id,
                ]);
            } else {
                Log::warning('No pending payment record found for order', ['order_id' => $orderId]);
            }

            // ✅ REMOVED: Don't manually update is_paid - the OrderHasPaidObserver handles this
            // The observer will automatically call syncOrderPaymentStatus() which updates is_paid

            DB::commit();

            // Reload order to get updated is_paid status from observer
            $order->refresh();

            Log::info('Payment processed - observer updated is_paid', [
                'order_id' => $order->id,
                'is_paid' => $order->is_paid,
                'status' => $order->status,
            ]);

            // TODO: Send order confirmation email
            // Mail::to($order->email)->send(new OrderConfirmation($order));

            return response()->json([
                'received' => true,
                'order_id' => $order->id,
                'message' => 'Payment processed successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update order payment status', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Payment update failed'], 500);
        }
    }

    /**
     * Handle expired/abandoned checkout sessions
     */
    protected function handleCheckoutExpired($session)
    {
        $orderId = $session->metadata->order_id ?? null;
        
        if (!$orderId) {
            Log::info('Checkout expired without order_id');
            return response()->json(['received' => true]);
        }

        $order = Order::find($orderId);
        
        if (!$order) {
            Log::warning('Order not found for expired session', ['order_id' => $orderId]);
            return response()->json(['received' => true]);
        }

        $payment = $order->orderHasPaids()
            ->where('method', 'stripe')
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($payment) {
            $notes = 'Checkout session expired.';
            $recoveryUrl = $session->after_expiration->recovery->url ?? null;

            if ($recoveryUrl) {
                $notes .= ' Recovery link available.';
            }

            $payment->update([
                'status' => 'failed',
                'transaction_id' => $session->id,
                'notes' => $notes,
            ]);

            Log::info('Payment marked as failed due to expiration', [
                'order_id' => $orderId,
                'recovery_url' => $recoveryUrl ? 'available' : 'none',
            ]);

            // TODO: Send abandoned cart recovery email
            // if ($recoveryUrl) {
            //     Mail::to($order->email)->queue(new AbandonedCartRecovery($order, $recoveryUrl));
            // }
        }

        return response()->json(['received' => true]);
    }
}