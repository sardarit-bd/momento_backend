<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'tgc_shipment_id',
        'tracking_number',
        'tracking_url',
        'shipped_at',
        'notified_at',
        'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'shipped_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}