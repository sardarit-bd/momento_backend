<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'addon_amount',
        'has_joker',
        'customization_mode',
        'card_design_count',
        'customization_images',
        'tuckbox_image_blob',  
        'tuckbox_image_mime',  
    ];

    protected $casts = [
        'price'                => 'decimal:2',
        'addon_amount'         => 'decimal:2',
        'has_joker'            => 'boolean',
        'card_design_count'    => 'integer',
        'customization_images' => 'array',
    ];

    protected $hidden = [
        'tuckbox_image_blob',
        'tuckbox_image_mime',
        'image_blob',
        'image_mime',
        'character_blob',
        'character_mime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function cards()
    {
        return $this->hasMany(OrderItemCard::class)->orderBy('position');
    }
}
