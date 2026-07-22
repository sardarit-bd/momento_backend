<?php

namespace App\Http\Controllers;

class PackageController extends Controller
{
    public function index()
    {
        return response()->json(
            collect(TradingCardPackage::cases())->map(fn ($pkg) => [
                'slug' => $pkg->value,
                'name' => $pkg->label(),
                'price' => $pkg->price() / 100,
                'cardCount' => $pkg->cardCount(),
            ])
        );
    }
}
