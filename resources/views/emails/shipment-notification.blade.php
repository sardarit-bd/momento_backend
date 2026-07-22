<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Order Has Shipped</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background: #f5f7fa; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #333; }
        .container { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #34D399, #059669); color: #fff; padding: 28px 20px; text-align: center; }
        .header .icon { font-size: 32px; margin-bottom: 4px; }
        .header h1 { margin: 4px 0 0; font-size: 22px; }
        .content { padding: 28px 24px; }
        .content p { line-height: 1.6; }
        .lede { font-size: 16px; }
        .highlight { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 20px 0; }
        .highlight .row { padding: 4px 0; }
        .highlight .label { color: #555; font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; }
        .highlight .value { font-size: 15px; font-weight: 600; color: #111; }
        .section-title { font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; color: #888; margin: 24px 0 8px; }
        .items { border: 1px solid #e6e6e6; border-radius: 8px; overflow: hidden; }
        .items table { width: 100%; border-collapse: collapse; }
        .items th { background: #f9fafb; text-align: left; padding: 10px 14px; font-size: 13px; color: #555; border-bottom: 1px solid #e6e6e6; }
        .items td { padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        .items tr:last-child td { border-bottom: none; }
        .button-wrap { text-align: center; margin: 28px 0 8px; }
        .button { display: inline-block; background: #059669; color: #fff !important; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; }
        .signoff { margin-top: 24px; }
        .footer { padding: 20px; border-top: 1px solid #e6e6e6; font-size: 13px; color: #777; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="icon">📦</div>
        <h1>You're one step closer!</h1>
    </div>
    <div class="content">
        <p class="lede">Hi {{ $customerName }},</p>
        <p>Good news — your order has left our hands and is now on its way to you. Here's a quick summary of what's shipping and how to keep an eye on it.</p>

        <div class="highlight">
            <div class="row">
                <div class="label">Order</div>
                <div class="value">#{{ $order->id }}</div>
            </div>
            @if ($shipment->carrier)
                <div class="row">
                    <div class="label">Carrier</div>
                    <div class="value">{{ $shipment->carrier }}</div>
                </div>
            @endif
            @if ($shipment->tracking_number)
                <div class="row">
                    <div class="label">Tracking number</div>
                    <div class="value">{{ $shipment->tracking_number }}</div>
                </div>
            @endif
        </div>

        <!-- @if ($orderItems->count())
            <div class="section-title">What's in this shipment</div>
            <div class="items">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orderItems as $item)
                            <tr>
                                <td>{{ optional($item->product)->name ?? 'Item' }}</td>
                                <td>{{ $item->quantity }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif -->

        @if ($shipment->tracking_url)
            <div class="button-wrap">
                <a href="{{ $shipment->tracking_url }}" class="button">Track Your Package</a>
            </div>
        @endif

        <p class="signoff">Thanks so much for your order — we hope it's worth the wait. If anything looks off once it arrives, just reply to this email and we'll sort it out.</p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </div>
</div>
</body>
</html>