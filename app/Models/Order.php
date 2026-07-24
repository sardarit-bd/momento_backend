<?php

namespace App\Models;

use App\Events\OrderPlaced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class Order extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [
        'user_id', 'name', 'email', 'phone',
        'address1', 'address2',
        'city', 'state', 'country', 'zipcode',
        'total', 'status', 'is_paid', 'is_customized', 'customized_file',
        'tgc_receipt_id', 'user_id', 'trading_box_pack_title', 'trading_box_created_for',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_customized' => 'boolean',
        'customized_file' => 'array',
    ];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function shippingInformation()
    {
        return $this->hasOne(ShippingInformation::class);
    }

    public function customizedCards()
    {
        return $this->hasManyThrough(OrderItemCard::class, OrderItem::class, 'order_id', 'order_item_id', 'id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orderHasPaids()
    {
        return $this->hasMany(OrderHasPaid::class);
    }

    /**
     * Define what should be pruned: pending orders older than 24 hours
     */
    public function prunable()
    {
        return static::where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(24));
    }

    protected $appends = ['customized_file_url'];

    public function getCustomizedFileUrlAttribute()
    {
        $file = $this->customized_file;

        if (empty($file)) {
            return null;
        }

        if (is_array($file)) {
            $file = $file[0];
        }

        if (! is_string($file)) {
            return null;
        }

        return asset('storage/'.$file);
    }

    /**
     * Boot the model and clean up related data when deleting
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function (self $order) {
            event(new OrderPlaced($order));
        });

        static::deleting(function ($order) {
            $order->orderItems()->delete();
            $order->orderHasPaids()->delete();
        });
    }
}
