<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BaseCard extends Model
{
    protected $table = 'base_cards';

    protected $fillable = ['product_id', 'name', 'image', 'card_type'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
