<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingInformation extends Model
{
    protected $table = 'shipping_information';

    protected $fillable = [
        'order_id',
        'first_name',
        'last_name',
        'phone',
        'address',
        'city',
        'zipcode',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

