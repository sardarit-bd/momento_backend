<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingCardPackage;

class TradingCardPackageController extends Controller
{
    public function index()
    {
        $packages = TradingCardPackage::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($pkg) => [
                'slug' => $pkg->slug,
                'name' => $pkg->name,
                'tag' => $pkg->tag,
                'subtitle' => $pkg->subtitle,
                'cardCount' => $pkg->card_count,
                'price' => $pkg->price,        // dollars, e.g. 29.00
                'features' => $pkg->features,
                'recommended' => $pkg->recommended,
            ]);

        return response()->json($packages);
    }
}