<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTradingCardOrderRequest;
use App\Models\Order;
use App\Models\TradingCardPackage;

class TradingCardOrderController extends Controller
{
    public function store(StoreTradingCardOrderRequest $request)
    {
        $validated = $request->validated();

        $package = TradingCardPackage::where('slug', $validated['package_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        $order = Order::create([
            'user_id' => $request->user()?->id,
            'product_slug' => $validated['product_slug'],
            'package_slug' => $package->slug,
            'template_id' => $validated['template_id'],
            'price_cents' => $package->price_cents, 
        ]);

        return response()->json($order, 201);
    }
}
