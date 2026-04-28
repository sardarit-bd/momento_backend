<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemCard extends Model
{
    protected $table = 'order_item_cards';

    protected $fillable = [
        'order_item_id',
        'card_pair_key',
        'card_type',
        'side',
        'rank',
        'position',
        'image_blob',
        'image_mime',
        'image_size_bytes',
        'image_sha256',
    ];

    protected $casts = [
        'position' => 'integer',
        'image_size_bytes' => 'integer',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
