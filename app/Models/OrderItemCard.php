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
        'slot_name',
        'position',
        'image_blob',
        'image_mime',
        'character_blob',
        'character_mime',
        'image_size_bytes',
        'image_sha256',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // IMPORTANT: image_blob and character_blob must NOT be in $hidden.
    // SerializesModels strips hidden fields when the job is queued, so the
    // job would always receive null blobs even when the DB has real data.
    // If you need to hide them from API responses, use an API Resource or
    // $visible on the specific resource — not $hidden on the model itself.
    // ─────────────────────────────────────────────────────────────────────
    protected $hidden = [
        // intentionally empty
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
