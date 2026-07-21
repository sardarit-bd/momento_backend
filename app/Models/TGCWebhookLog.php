<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TGCWebhookLog extends Model
{
    protected $table = 'tgc_webhook_logs';

    protected $fillable = [
        'tgc_webhook_event_id',
        'type',
        'event',
        'tgc_receipt_id',
        'dedupe_key',
        'hmac_verified',
        'matched_order_id',
        'status',
        'payload',
        'received_at',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'hmac_verified' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
