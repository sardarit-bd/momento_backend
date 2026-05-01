<?php

namespace App\Http\Controllers\PaymentGateway;

use App\Models\Order;
use App\Models\ShippingInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );

            Log::info('Stripe webhook received', [
                'type' => $event->type,
                'id'   => $event->id,
            ]);

            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'checkout.session.expired':
                    return $this->handleCheckoutExpired($event->data->object);

                default:
                    Log::info('Unhandled webhook event', ['type' => $event->type]);
                    return response()->json(['received' => true]);
            }

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);

        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Webhook failed'], 500);
        }
    }

    protected function handleCheckoutCompleted($session)
    {
        $orderId = $session->metadata->order_id ?? null;

        if (!$orderId) {
            Log::error('Missing order_id in Stripe metadata');
            return response()->json(['error' => 'Missing order ID'], 400);
        }

        Log::info('Processing payment completion', [
            'order_id'          => $orderId,
            'stripe_session_id' => $session->id,
        ]);

        $order = Order::with('orderHasPaids')->find($orderId);

        if (!$order) {
            Log::error('Order not found', ['order_id' => $orderId]);
            return response()->json(['error' => 'Order not found'], 404);
        }

        DB::beginTransaction();

        try {
            // 1. Update payment record
            $payment = $order->orderHasPaids()
                ->where('method', 'stripe')
                ->latest()
                ->first();

            if ($payment) {
                $payment->update([
                    'status'         => 'completed',
                    'transaction_id' => $session->payment_intent ?? $session->id,
                    'notes'          => 'Payment completed successfully via Stripe.',
                ]);

                Log::info('Payment record marked as completed', [
                    'payment_id'     => $payment->id,
                    'transaction_id' => $session->payment_intent ?? $session->id,
                ]);
            } else {
                $payment = $order->orderHasPaids()->create([
                    'amount' => $order->total,
                    'method' => 'stripe',
                    'status' => 'completed',
                    'transaction_id' => $session->payment_intent ?? $session->id,
                    'notes' => 'Payment completed successfully via Stripe (created by webhook reconciliation).',
                ]);

                Log::warning('Stripe payment row missing; created completed row from webhook', [
                    'order_id' => $orderId,
                    'payment_id' => $payment->id,
                ]);
            }

            // 2. Update order as paid and completed ✅
            $order->update([
                'is_paid'           => true,
                'status'            => 'completed',
                'stripe_session_id' => $session->id,
            ]);

            Log::info('Order marked as paid and completed', [
                'order_id'       => $order->id,
                'transaction_id' => $session->payment_intent ?? $session->id,
            ]);

            // 3. Save shipping info if not already saved
            ShippingInformation::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'first_name' => (string) ($session->metadata->first_name ?? ''),
                    'last_name'  => (string) ($session->metadata->last_name ?? ''),
                    'phone'      => (string) ($session->metadata->phone ?? ''),
                    'address'    => (string) ($session->metadata->address ?? ''),
                    'city'       => (string) ($session->metadata->city ?? ''),
                    'zipcode'    => (string) ($session->metadata->zipcode ?? ''),
                ]
            );

            DB::commit();

            Log::info('Webhook processing complete', [
                'order_id' => $order->id,
                'is_paid'  => $order->is_paid,
                'status'   => $order->status,
            ]);

            return response()->json([
                'received' => true,
                'order_id' => $order->id,
                'message'  => 'Payment processed successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to process completed payment', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Payment update failed'], 500);
        }
    }

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

        // Don't touch already paid orders
        if ($order->is_paid) {
            Log::warning('Expired session but order already paid — skipping', ['order_id' => $orderId]);
            return response()->json(['received' => true]);
        }

        DB::beginTransaction();

        try {
            $payment = $order->orderHasPaids()
                ->where('method', 'stripe')
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($payment) {
                $notes = 'Checkout session expired.';
                $recoveryUrl = $session->after_expiration->recovery->url ?? null;

                if ($recoveryUrl) {
                    $notes .= ' Recovery link: ' . $recoveryUrl;
                }

                $payment->update([
                    'status'         => 'failed',
                    'transaction_id' => $session->id,
                    'notes'          => $notes,
                ]);

                Log::info('Payment marked as failed due to expiration', [
                    'order_id'     => $orderId,
                    'recovery_url' => $recoveryUrl ?? 'none',
                ]);
            }

            $order->update([
                'is_paid' => false,
                'status'  => 'pending',
            ]);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to process expired session', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true]);
    }
}
