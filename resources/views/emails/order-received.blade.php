<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Received</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background: #f5f7fa; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #333; }
        .container { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #3CA9FF, #0077CC); color: #fff; padding: 24px 20px; text-align: center; }
        .content { padding: 24px 20px; }
        .content p { line-height: 1.6; }
        .highlight { background: #f0f9ff; border: 1px solid #cfe8ff; border-radius: 8px; padding: 14px; margin: 16px 0; }
        .footer { padding: 20px; border-top: 1px solid #e6e6e6; font-size: 13px; color: #777; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Order Received</h1>
    </div>
    <div class="content">
        <p>Hello {{ $customerName }},</p>
        <p>We have received your order and it is being processed. Thank you for shopping with us.</p>
        <div class="highlight">
            <strong>Order ID:</strong> #{{ $order->id }}<br>
            <strong>Total:</strong> ${{ number_format((float) $order->total, 2) }}
        </div>
        <p>We will update you as soon as your order moves to the next stage.</p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </div>
</div>
</body>
</html>
