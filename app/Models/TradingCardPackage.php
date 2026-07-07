<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingCardPackage extends Model
{
    protected $fillable = [
        'slug', 'name', 'tag', 'subtitle', 'card_count',
        'price_cents', 'features', 'recommended', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'recommended' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Convenience accessor for display / API responses
    protected function price(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->price_cents / 100,
        );
    }
}
